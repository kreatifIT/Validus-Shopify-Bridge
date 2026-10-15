<?php

namespace Tests\Feature;

use App\Mail\NewsletterConfirmation;
use Illuminate\Support\Facades\Mail;
use Statamic\Events\SubmissionCreated;
use Statamic\Facades\Form;
use Statamic\Facades\Site;
use Tests\TestCase;

class SubscribeContactToNewsletterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config([
            'validus-shopify.shopify.store_url' => 'shop.test',
            'validus-shopify.shopify.admin_token' => 'token',
        ]);
    }

    public function test_the_marketing_toggle_mails_the_double_opt_in_in_the_language_of_the_page(): void
    {
        session()->setPreviousUrl(Site::get('it')->absoluteUrl().'/contatti');

        SubmissionCreated::dispatch($this->submission(['newsletter' => true]));

        Mail::assertQueued(NewsletterConfirmation::class, fn (NewsletterConfirmation $mail) => $mail->hasTo('anna@example.com')
            && $mail->locale === 'it');
    }

    public function test_nothing_happens_without_the_toggle(): void
    {
        SubmissionCreated::dispatch($this->submission(['newsletter' => false]));

        Mail::assertNothingQueued();
    }

    /** SubmissionCreated fires twice per contact submission. */
    public function test_a_submission_is_mailed_once(): void
    {
        $submission = $this->submission(['newsletter' => true]);
        SubmissionCreated::dispatch($submission);
        SubmissionCreated::dispatch($submission);

        Mail::assertQueued(NewsletterConfirmation::class, 1);
    }

    public function test_without_the_shopify_connection_nothing_is_mailed(): void
    {
        config(['validus-shopify.shopify.store_url' => null]);

        SubmissionCreated::dispatch($this->submission(['newsletter' => true]));

        Mail::assertNothingQueued();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function submission(array $data)
    {
        return Form::find('contact')->makeSubmission()->data(array_merge([
            'email' => 'anna@example.com',
            'first_name' => 'Anna',
            'last_name' => 'Rossi',
            'consent' => true,
        ], $data));
    }
}
