<?php

namespace App\Jobs;

use App\Support\NewsletterSignup;
use App\Support\ShopifyNewsletter;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Saves a newsletter sign-up in Shopify once its confirmation link was clicked.
 *
 * Queued like FileIubendaConsent: the visitor has confirmed and is shown the
 * confirmation page right away, so a Shopify outage must not reach them - a
 * failure is retried and then lands in failed_jobs, where the watchdog reports
 * it. On the sync queue it is only logged.
 */
class SubscribeToNewsletter implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    /** @var list<int> */
    public $backoff = [60, 300];

    /**
     * @param  string  $confirmedAt  ISO 8601 time of the click, kept as the consent's date across retries.
     */
    public function __construct(
        public NewsletterSignup $signup,
        public string $confirmedAt,
    ) {}

    public function handle(ShopifyNewsletter $shopify): void
    {
        try {
            $shopify->subscribe($this->signup, CarbonImmutable::parse($this->confirmedAt));
        } catch (\Throwable $e) {
            if (! $this->runsOnSyncQueue()) {
                throw $e;
            }

            $this->logFailure($e);
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->logFailure($e);
    }

    private function runsOnSyncQueue(): bool
    {
        return $this->job === null || $this->job->getConnectionName() === 'sync';
    }

    private function logFailure(\Throwable $e): void
    {
        Log::error('Newsletter sign-up could not be saved in Shopify: '.$e->getMessage(), [
            'site' => $this->signup->site,
            'confirmed_at' => $this->confirmedAt,
            'attempts' => $this->attempts(),
        ]);
    }
}
