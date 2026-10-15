<?php

namespace App\Mail;

use App\Support\NewsletterSignup;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Statamic\View\Cascade;

/**
 * The double opt-in mail of a newsletter sign-up, see NewsletterSignup. Sent in
 * the language of the sign-up (Mail::locale()), which the subject and the
 * templates (email/newsletter_confirmation*) are translated in.
 */
class NewsletterConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $confirmationUrl, public string $siteUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('strings.newsletter_mail_subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'email.newsletter_confirmation',
            text: 'email.newsletter_confirmation_text',
            with: [
                // {{ config:app:url }} for the logo. Statamic's form mails get it the same way.
                'config' => Cascade::config(),
                'confirmation_url' => $this->confirmationUrl,
                'site_url' => $this->siteUrl,
                'valid_days' => NewsletterSignup::LINK_VALID_DAYS,
            ],
        );
    }
}
