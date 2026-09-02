<?php

namespace Kreatif\ValidusShopifyBridge\Clients;

use Illuminate\Support\Facades\Http;
use Kreatif\ValidusShopifyBridge\Exceptions\ValidusApiException;

class ValidusClient
{
    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
        protected int $timeout = 30,
    ) {}

    /**
     * @return array<int, array<string, mixed>> Raw "products" entries, one per Validus product (= one Shopify variant).
     */
    public function getProducts(): array
    {
        $response = $this->request()->get("{$this->baseUrl}/products");

        return $this->decode($response, '/products')['products'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload  See docs/postman for the exact shape expected by Validus.
     */
    public function createOrder(array $payload): void
    {
        $response = $this->request()->post("{$this->baseUrl}/orders", $payload);

        $this->decode($response, '/orders');
    }

    protected function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders(['X-API-KEY' => $this->apiKey])
            ->timeout($this->timeout)
            ->acceptJson();
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(\Illuminate\Http\Client\Response $response, string $endpoint): array
    {
        if ($response->failed()) {
            throw ValidusApiException::requestFailed($endpoint, $response->status(), $response->body());
        }

        $body = $response->json() ?? [];

        if (($body['success'] ?? false) !== true) {
            throw ValidusApiException::unsuccessful($endpoint, $response->body());
        }

        return $body;
    }
}
