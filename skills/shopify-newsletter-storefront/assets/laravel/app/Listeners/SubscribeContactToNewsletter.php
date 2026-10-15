<?php

namespace App\Listeners;

use App\Support\NewsletterSignup;
use App\Support\ShopifyNewsletter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Statamic\Events\SubmissionCreated;
use Statamic\Facades\Site;

/**
 * Signs contact form senders up for the newsletter when they ticked the marketing
 * toggle ("Ich möchte Marketing Nachrichten erhalten") - by the same double opt-in
 * as the newsletter module, so the toggle alone never puts anyone on the list.
 *
 * The language is taken from the page the form was sent from, as Statamic's own
 * FormController does for the mails. The confirmation mail is queued, and a
 * failure to queue it is only logged: the enquiry itself must go through.
 */
class SubscribeContactToNewsletter
{
    /**
     * Submission ids already handled in this request: SubmissionCreated fires twice
     * per submission, see SendIubendaConsent.
     *
     * @var array<string, true>
     */
    private static array $subscribed = [];

    public function __construct(private ShopifyNewsletter $shopify) {}

    public function handle(SubmissionCreated $event): void
    {
        $submission = $event->submission;

        if ($submission->form()->handle() !== 'contact' || ! $submission->get('newsletter')) {
            return;
        }

        $email = $submission->get('email');
        $site = (Site::findByUrl(URL::previous()) ?? Site::default())->handle();

        if (! $email || ! $this->shopify->isConfigured() || isset(self::$subscribed[$submission->id()])) {
            return;
        }

        self::$subscribed[$submission->id()] = true;

        try {
            (new NewsletterSignup((string) $email, $submission->get('first_name'), $submission->get('last_name'), $site))
                ->requestConfirmation();
        } catch (\Throwable $e) {
            Log::error('Newsletter confirmation mail for a contact form sender could not be sent: '.$e->getMessage(), [
                'submission_id' => $submission->id(),
                'site' => $site,
            ]);
        }
    }
}
