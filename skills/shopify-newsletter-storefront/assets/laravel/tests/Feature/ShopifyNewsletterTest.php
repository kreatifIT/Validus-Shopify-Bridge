<?php

namespace Tests\Feature;

use App\Jobs\SubscribeToNewsletter;
use App\Support\NewsletterSignup;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A confirmed sign-up arriving in Shopify (SubscribeToNewsletter ->
 * ShopifyNewsletter), against a faked Admin API.
 */
class ShopifyNewsletterTest extends TestCase
{
    private const ENDPOINT = 'https://shop.test/admin/api/2025-04/graphql.json';

    private const CONFIRMED_AT = '2026-10-08T10:15:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'validus-shopify.shopify.store_url' => 'shop.test',
            'validus-shopify.shopify.admin_token' => 'token',
            'validus-shopify.shopify.api_version' => '2025-04',
        ]);
    }

    public function test_a_new_subscriber_becomes_a_customer_with_confirmed_consent(): void
    {
        $this->shopifyAnswers(
            ['customerByIdentifier' => null],
            ['customerCreate' => ['customer' => ['id' => 'gid://shopify/Customer/1'], 'userErrors' => []]],
        );

        $this->subscribe(new NewsletterSignup('Anna@Example.com', 'Anna', 'Rossi', 'it'));

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === self::ENDPOINT
            && str_contains($request['query'], 'customerByIdentifier')
            && $request['variables'] === ['email' => 'anna@example.com']);
        Http::assertSent(fn (Request $request) => str_contains($request['query'], 'customerCreate')
            && $request['variables']['input'] === [
                'email' => 'anna@example.com',
                'firstName' => 'Anna',
                'lastName' => 'Rossi',
                'locale' => 'it',
                'tags' => ['newsletter', 'newsletter-it'],
                'emailMarketingConsent' => [
                    'marketingState' => 'SUBSCRIBED',
                    'marketingOptInLevel' => 'CONFIRMED_OPT_IN',
                    'consentUpdatedAt' => self::CONFIRMED_AT,
                ],
            ]);
    }

    public function test_names_left_out_are_not_sent(): void
    {
        $this->shopifyAnswers(
            ['customerByIdentifier' => null],
            ['customerCreate' => ['customer' => ['id' => 'gid://shopify/Customer/1'], 'userErrors' => []]],
        );

        $this->subscribe(new NewsletterSignup('anna@example.com', null, '', 'de'));

        Http::assertSent(fn (Request $request) => str_contains($request['query'], 'customerCreate')
            && ! array_key_exists('firstName', $request['variables']['input'])
            && ! array_key_exists('lastName', $request['variables']['input'])
            && $request['variables']['input']['locale'] === 'de');
    }

    /** Someone who has ordered before keeps their customer record, name and language. */
    public function test_an_existing_customer_gets_the_consent_and_the_tags(): void
    {
        $this->shopifyAnswers(
            ['customerByIdentifier' => ['id' => 'gid://shopify/Customer/7', 'emailMarketingConsent' => ['marketingState' => 'NOT_SUBSCRIBED']]],
            ['customerEmailMarketingConsentUpdate' => ['userErrors' => []]],
            ['tagsAdd' => ['userErrors' => []]],
        );

        $this->subscribe(new NewsletterSignup('anna@example.com', 'Anna', 'Rossi', 'en'));

        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request) => str_contains($request['query'], 'customerCreate'));
        Http::assertSent(fn (Request $request) => str_contains($request['query'], 'customerEmailMarketingConsentUpdate')
            && $request['variables']['input'] === [
                'customerId' => 'gid://shopify/Customer/7',
                'emailMarketingConsent' => [
                    'marketingState' => 'SUBSCRIBED',
                    'marketingOptInLevel' => 'CONFIRMED_OPT_IN',
                    'consentUpdatedAt' => self::CONFIRMED_AT,
                ],
            ]);
        Http::assertSent(fn (Request $request) => str_contains($request['query'], 'tagsAdd')
            && $request['variables'] === ['id' => 'gid://shopify/Customer/7', 'tags' => ['newsletter', 'newsletter-en']]);
    }

    /** A consent given earlier, e.g. at checkout, keeps its date. */
    public function test_an_existing_subscriber_only_gets_the_tags(): void
    {
        $this->shopifyAnswers(
            ['customerByIdentifier' => ['id' => 'gid://shopify/Customer/7', 'emailMarketingConsent' => ['marketingState' => 'SUBSCRIBED']]],
            ['tagsAdd' => ['userErrors' => []]],
        );

        $this->subscribe(new NewsletterSignup('anna@example.com', 'Anna', 'Rossi', 'de'));

        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => str_contains($request['query'], 'customerEmailMarketingConsentUpdate'));
    }

    public function test_a_refusal_by_shopify_is_logged_without_the_address(): void
    {
        $this->shopifyAnswers(
            ['customerByIdentifier' => null],
            ['customerCreate' => ['customer' => null, 'userErrors' => [['field' => ['email'], 'message' => 'Email has already been taken']]]],
        );
        Log::spy();

        $this->subscribe(new NewsletterSignup('anna@example.com', 'Anna', 'Rossi', 'de'));

        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => str_contains($message, 'could not be saved in Shopify')
            && str_contains($message, 'Email has already been taken')
            && $context['site'] === 'de'
            && $context['confirmed_at'] === self::CONFIRMED_AT
            && ! str_contains(json_encode($context), 'anna@example.com'));
    }

    /**
     * @param  array<string, mixed>  ...$answers  The "data" of each response, in order.
     */
    private function shopifyAnswers(array ...$answers): void
    {
        $sequence = Http::sequence();

        foreach ($answers as $data) {
            $sequence->push(['data' => $data]);
        }

        Http::fake(['shop.test/*' => $sequence]);
    }

    private function subscribe(NewsletterSignup $signup): void
    {
        SubscribeToNewsletter::dispatchSync($signup, self::CONFIRMED_AT);
    }
}
