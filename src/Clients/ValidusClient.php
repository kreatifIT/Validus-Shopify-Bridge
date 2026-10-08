<?php

namespace Kreatif\ValidusShopifyBridge\Clients;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kreatif\ValidusShopifyBridge\Exceptions\ValidusApiException;
use Throwable;

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
        $this->recordOrderRequest($payload);

        $response = $this->request()->post("{$this->baseUrl}/orders", $payload);

        $this->decode($response, '/orders');
    }

    /**
     * Writes the exact payload about to be sent to <disk>/<directory>/<orderId>.json
     * - see config('validus-shopify.order_export') - so it's still on hand
     * to compare against a Validus rejection without reconstructing it from
     * the Shopify order by hand. Best-effort: a filesystem problem here must
     * not stop the actual order export.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function recordOrderRequest(array $payload): void
    {
        $disk = config('validus-shopify.order_export.request_log_disk');

        if (! $disk) {
            return;
        }

        $directory = config('validus-shopify.order_export.request_log_directory', 'validus-order-requests');
        $orderId = $payload['orderId'] ?? 'unknown';

        try {
            Storage::disk($disk)->put(
                "{$directory}/{$orderId}.json",
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        } catch (Throwable $e) {
            report($e);
        }
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
