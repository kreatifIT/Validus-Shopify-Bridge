<?php

namespace Kreatif\ValidusShopifyBridge\Shopify;

use Illuminate\Support\Facades\Http;
use Kreatif\ValidusShopifyBridge\Exceptions\ShopifyApiException;

/**
 * Talks to the Shopify Admin GraphQL API directly over Laravel's own HTTP
 * client instead of a third-party Shopify SDK. The endpoint shape is public,
 * stable Shopify API surface (POST JSON to /admin/api/{version}/graphql.json
 * with an X-Shopify-Access-Token header) - not worth pulling in a dependency
 * for, especially since shopify/shopify-api is abandoned upstream. This also
 * makes the client trivially fakeable with Http::fake() in tests, unlike the
 * SDK's raw-Guzzle transport.
 */
class ShopifyGraphqlClient
{
    public function __construct(
        protected string $storeDomain,
        protected string $adminToken,
        protected string $apiVersion,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed> The "data" portion of the response.
     */
    public function query(string $query, array $variables = []): array
    {
        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->adminToken,
            'Content-Type' => 'application/json',
        ])->post($this->endpoint(), [
            'query' => $query,
            'variables' => $variables,
        ]);

        if ($response->failed()) {
            throw ShopifyApiException::requestFailed($response->status(), $response->body());
        }

        $body = $response->json() ?? [];

        if (! empty($body['errors'])) {
            throw ShopifyApiException::graphqlErrors($body['errors']);
        }

        return $body['data'] ?? [];
    }

    protected function endpoint(): string
    {
        return "https://{$this->storeDomain}/admin/api/{$this->apiVersion}/graphql.json";
    }
}
