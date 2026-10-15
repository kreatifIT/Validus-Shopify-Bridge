<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Kreatif\ValidusShopifyBridge\Exceptions\ShopifyApiException;
use Kreatif\ValidusShopifyBridge\Shopify\ShopifyGraphqlClient;

/**
 * Saves a confirmed newsletter sign-up in Shopify: the subscriber becomes a
 * customer whose e-mail marketing consent is SUBSCRIBED, confirmed by double
 * opt-in, so Shopify Email can mail them.
 *
 * An existing customer - someone who has ordered before, say - is looked up by
 * the exact address (customerByIdentifier; the `email:` search filter matches
 * parts of addresses too) and only gets the consent, keeping their name and
 * language. A consent they already have is left as it is.
 *
 * Every subscriber is tagged `newsletter` and `newsletter-{language}`, so that
 * Shopify Email can address each language separately.
 *
 * Goes through the bridge's Shopify connection (config/validus-shopify.php),
 * whose app needs the write_customers scope for this.
 */
class ShopifyNewsletter
{
    public const TAG = 'newsletter';

    public function __construct(protected ShopifyGraphqlClient $shopify) {}

    public function isConfigured(): bool
    {
        return config('validus-shopify.shopify.store_url') && config('validus-shopify.shopify.admin_token');
    }

    /**
     * @param  CarbonInterface  $confirmedAt  When the confirmation link was clicked.
     *
     * @throws ShopifyApiException
     */
    public function subscribe(NewsletterSignup $signup, CarbonInterface $confirmedAt): void
    {
        $consent = [
            'marketingState' => 'SUBSCRIBED',
            'marketingOptInLevel' => 'CONFIRMED_OPT_IN',
            'consentUpdatedAt' => $confirmedAt->toIso8601String(),
        ];

        $customer = $this->findCustomer($signup->email);

        if ($customer === null) {
            $this->createCustomer($signup, $consent);

            return;
        }

        if (Arr::get($customer, 'emailMarketingConsent.marketingState') !== 'SUBSCRIBED') {
            $this->updateConsent($customer['id'], $consent);
        }

        $this->addTags($customer['id'], $this->tags($signup));
    }

    /**
     * @return array{id: string, emailMarketingConsent: array{marketingState: string}|null}|null
     */
    protected function findCustomer(string $email): ?array
    {
        $query = <<<'GRAPHQL'
            query CustomerByEmail($email: String!) {
                customerByIdentifier(identifier: { emailAddress: $email }) {
                    id
                    emailMarketingConsent { marketingState }
                }
            }
        GRAPHQL;

        return Arr::get($this->shopify->query($query, ['email' => $email]), 'customerByIdentifier');
    }

    /**
     * @param  array<string, string>  $consent
     */
    protected function createCustomer(NewsletterSignup $signup, array $consent): void
    {
        $mutation = <<<'GRAPHQL'
            mutation CreateSubscriber($input: CustomerInput!) {
                customerCreate(input: $input) {
                    customer { id }
                    userErrors { field message }
                }
            }
        GRAPHQL;

        $this->mutate('customerCreate', $mutation, ['input' => array_filter([
            'email' => $signup->email,
            'firstName' => $signup->firstName,
            'lastName' => $signup->lastName,
            'locale' => $signup->language(),
            'tags' => $this->tags($signup),
            'emailMarketingConsent' => $consent,
        ])]);
    }

    /**
     * @param  array<string, string>  $consent
     */
    protected function updateConsent(string $customerId, array $consent): void
    {
        $mutation = <<<'GRAPHQL'
            mutation SubscribeCustomer($input: CustomerEmailMarketingConsentUpdateInput!) {
                customerEmailMarketingConsentUpdate(input: $input) {
                    userErrors { field message code }
                }
            }
        GRAPHQL;

        $this->mutate('customerEmailMarketingConsentUpdate', $mutation, ['input' => [
            'customerId' => $customerId,
            'emailMarketingConsent' => $consent,
        ]]);
    }

    /**
     * @param  list<string>  $tags
     */
    protected function addTags(string $customerId, array $tags): void
    {
        $mutation = <<<'GRAPHQL'
            mutation TagSubscriber($id: ID!, $tags: [String!]!) {
                tagsAdd(id: $id, tags: $tags) {
                    userErrors { field message }
                }
            }
        GRAPHQL;

        $this->mutate('tagsAdd', $mutation, ['id' => $customerId, 'tags' => $tags]);
    }

    /**
     * @param  array<string, mixed>  $variables
     *
     * @throws ShopifyApiException when Shopify refuses the change
     */
    protected function mutate(string $name, string $mutation, array $variables): void
    {
        $errors = Arr::get($this->shopify->query($mutation, $variables), "{$name}.userErrors", []);

        if ($errors !== []) {
            throw ShopifyApiException::userErrors($name, $errors);
        }
    }

    /**
     * @return list<string>
     */
    protected function tags(NewsletterSignup $signup): array
    {
        return [self::TAG, self::TAG.'-'.$signup->language()];
    }
}
