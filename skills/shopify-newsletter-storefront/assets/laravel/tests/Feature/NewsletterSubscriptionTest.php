<?php

namespace Tests\Feature;

use App\Jobs\SubscribeToNewsletter;
use App\Mail\NewsletterConfirmation;
use App\Support\NewsletterSignup;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Statamic\Facades\Site;
use Tests\TestCase;

/**
 * The newsletter module's sign-up and the confirmation link of its double
 * opt-in mail. What happens in Shopify afterwards: ShopifyNewsletterTest.
 */
class NewsletterSubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Mail::fake();
        Queue::fake();

        config([
            'services.recaptcha.secret' => null,
            'services.newsletter.confirmed_urls' => ['de' => null, 'it' => null, 'en' => 'https://example.com/en/thanks'],
            'validus-shopify.shopify.store_url' => 'shop.test',
            'validus-shopify.shopify.admin_token' => 'token',
        ]);
    }

    public function test_the_sign_up_mails_a_confirmation_link_in_the_visitors_language(): void
    {
        $this->postJson('/!/newsletter', $this->signUp(['site' => 'it', 'email' => ' Anna@Example.com ']))
            ->assertOk()
            ->assertJson(['subscribed' => true]);

        Mail::assertQueued(NewsletterConfirmation::class, fn (NewsletterConfirmation $mail) => $mail->hasTo('anna@example.com')
            && $mail->locale === 'it'
            && $mail->siteUrl === Site::get('it')->absoluteUrl());

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    /** The address is encrypted in the link, so it does not show up in URLs or logs. */
    public function test_the_link_does_not_reveal_the_address(): void
    {
        $this->postJson('/!/newsletter', $this->signUp())->assertOk();

        $url = $this->confirmationUrl();

        $this->assertStringStartsWith(route('newsletter.confirm'), $url);
        $this->assertStringNotContainsString('anna', strtolower(urldecode($url)));
    }

    public function test_the_link_hands_the_sign_up_to_shopify_and_leads_back_to_the_site(): void
    {
        $this->freezeSecond();
        $this->postJson('/!/newsletter', $this->signUp(['site' => 'it']))->assertOk();

        $this->get($this->confirmationUrl())->assertRedirect(Site::get('it')->absoluteUrl());

        Queue::assertPushed(SubscribeToNewsletter::class, fn (SubscribeToNewsletter $job) => $job->signup->email === 'anna@example.com'
            && $job->signup->firstName === 'Anna'
            && $job->signup->lastName === 'Rossi'
            && $job->signup->site === 'it'
            && $job->confirmedAt === now()->toIso8601String());
    }

    public function test_a_configured_page_replaces_the_home_page(): void
    {
        $this->postJson('/!/newsletter', $this->signUp(['site' => 'en']))->assertOk();

        $this->get($this->confirmationUrl())->assertRedirect('https://example.com/en/thanks');
    }

    public function test_names_are_optional(): void
    {
        $this->postJson('/!/newsletter', $this->signUp(['first_name' => '', 'last_name' => ' ']))->assertOk();

        $this->get($this->confirmationUrl());

        Queue::assertPushed(SubscribeToNewsletter::class, fn (SubscribeToNewsletter $job) => $job->signup->firstName === null
            && $job->signup->lastName === null);
    }

    public function test_an_altered_link_gets_the_link_page(): void
    {
        $this->postJson('/!/newsletter', $this->signUp())->assertOk();

        $this->get(str_replace('signup=', 'signup=x', $this->confirmationUrl()))
            ->assertForbidden()
            ->assertSee('Dieser Link ist nicht mehr gültig')
            ->assertSee('href="'.Site::get('de')->absoluteUrl().'"', false);

        Queue::assertNothingPushed();
    }

    /** The link still says which language the visitor signed up in. */
    public function test_an_expired_link_gets_the_link_page_in_its_language(): void
    {
        $this->postJson('/!/newsletter', $this->signUp(['site' => 'it']))->assertOk();
        $url = $this->confirmationUrl();

        $this->travel(NewsletterSignup::LINK_VALID_DAYS)->days();
        $this->travel(1)->minute();

        $this->get($url)
            ->assertForbidden()
            ->assertSee('Questo link non è più valido')
            ->assertSee('href="'.Site::get('it')->absoluteUrl().'"', false);

        Queue::assertNothingPushed();
    }

    /** A signed link whose content was not made by NewsletterSignup. */
    public function test_a_link_without_a_sign_up_gets_the_link_page(): void
    {
        $this->get(URL::temporarySignedRoute('newsletter.confirm', now()->addDay(), ['signup' => 'nonsense']))
            ->assertForbidden()
            ->assertSee('Dieser Link ist nicht mehr gültig');

        Queue::assertNothingPushed();
    }

    public function test_it_needs_the_consent(): void
    {
        $this->postJson('/!/newsletter', $this->signUp(['consent' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['consent']);

        Mail::assertNothingQueued();
    }

    public function test_it_rejects_an_unknown_site(): void
    {
        $this->postJson('/!/newsletter', $this->signUp(['site' => 'fr']))
            ->assertJsonValidationErrors(['site']);

        Mail::assertNothingQueued();
    }

    /** Bots get the same answer as people, so they do not learn about the trap. */
    public function test_a_filled_honeypot_is_answered_but_not_mailed(): void
    {
        $this->postJson('/!/newsletter', $this->signUp(['website' => 'https://spam.example']))
            ->assertOk()
            ->assertJson(['subscribed' => true]);

        Mail::assertNothingQueued();
    }

    /** Without Shopify the link would lead nowhere, so no mail goes out. */
    public function test_without_the_shopify_connection_nothing_is_mailed(): void
    {
        config(['validus-shopify.shopify.admin_token' => null]);
        Log::spy();

        $this->postJson('/!/newsletter', $this->signUp())
            ->assertStatus(503)
            ->assertJson(['subscribed' => false]);

        Mail::assertNothingQueued();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Shopify connection is not configured'));
    }

    public function test_the_mail_carries_the_link_in_the_language_of_the_sign_up(): void
    {
        $mail = (new NewsletterConfirmation('https://example.com/confirm?a=1&b=2', 'https://example.com/it'))->locale('it');

        $mail->assertHasSubject('Conferma la tua iscrizione alla newsletter');
        $mail->assertSeeInHtml('Conferma iscrizione');
        $mail->assertSeeInHtml('href="https://example.com/confirm?a=1&amp;b=2"', false);
        $mail->assertSeeInHtml('valido per 7 giorni');
        $mail->assertSeeInHtml('src="'.config('app.url').'/visuals/logo-white-vertical-pictogram.png"', false);
        $mail->assertSeeInText('https://example.com/confirm?a=1&b=2', false);
    }

    private function confirmationUrl(): string
    {
        $url = null;

        Mail::assertQueued(NewsletterConfirmation::class, function (NewsletterConfirmation $mail) use (&$url) {
            $url = $mail->confirmationUrl;

            return true;
        });

        return $url;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function signUp(array $overrides = []): array
    {
        return array_merge([
            'email' => 'anna@example.com',
            'first_name' => 'Anna',
            'last_name' => 'Rossi',
            'consent' => '1',
            'site' => 'de',
        ], $overrides);
    }
}
