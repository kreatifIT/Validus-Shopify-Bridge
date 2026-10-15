<?php

namespace App\Support;

use App\Mail\NewsletterConfirmation;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Statamic\Facades\Site;

/**
 * A newsletter sign-up waiting for its double opt-in.
 *
 * Shopify sends no confirmation mail for customers created through the Admin
 * API, so the site sends its own (NewsletterConfirmation). Only the click on its
 * link (NewsletterController::confirm) hands the sign-up to Shopify, so an address
 * typed in by someone else never ends up receiving newsletters.
 *
 * Nothing is stored in the meantime: the link carries the sign-up itself,
 * encrypted - so the address does not appear in plain text in URLs or server
 * logs - and signed, valid for LINK_VALID_DAYS.
 *
 * Used by the newsletter module (NewsletterController) and by the contact form's
 * marketing toggle (SubscribeContactToNewsletter).
 */
class NewsletterSignup
{
    public const LINK_VALID_DAYS = 7;

    public readonly string $email;

    public readonly ?string $firstName;

    public readonly ?string $lastName;

    public function __construct(string $email, ?string $firstName, ?string $lastName, public readonly string $site)
    {
        $this->email = strtolower(trim($email));
        $this->firstName = trim((string) $firstName) ?: null;
        $this->lastName = trim((string) $lastName) ?: null;
    }

    /**
     * Queues the confirmation mail, in the language the visitor signed up in.
     */
    public function requestConfirmation(): void
    {
        Mail::to($this->email)
            ->locale($this->language())
            ->queue(new NewsletterConfirmation($this->confirmationUrl(), Site::get($this->site)->absoluteUrl()));
    }

    public function confirmationUrl(): string
    {
        return URL::temporarySignedRoute('newsletter.confirm', now()->addDays(self::LINK_VALID_DAYS), [
            'signup' => Crypt::encryptString(json_encode([
                'email' => $this->email,
                'first_name' => $this->firstName,
                'last_name' => $this->lastName,
                'site' => $this->site,
            ])),
        ]);
    }

    /**
     * The sign-up carried by a confirmation link.
     *
     * @throws DecryptException when the value was not made by confirmationUrl()
     */
    public static function fromLink(string $value): self
    {
        $data = json_decode(Crypt::decryptString($value), true);

        if (! is_array($data) || empty($data['email']) || ! Site::get($data['site'] ?? '')) {
            throw new DecryptException('The newsletter sign-up in the link is incomplete.');
        }

        return new self($data['email'], $data['first_name'] ?? null, $data['last_name'] ?? null, $data['site']);
    }

    /**
     * The site's language code ("de", "it", "en"), as used for the translations.
     */
    public function language(): string
    {
        return Site::get($this->site)->lang();
    }
}
