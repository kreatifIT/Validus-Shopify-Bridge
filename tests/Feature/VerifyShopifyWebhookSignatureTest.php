<?php

namespace Kreatif\ValidusShopifyBridge\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Kreatif\ValidusShopifyBridge\Jobs\ExportOrderToValidusJob;
use Kreatif\ValidusShopifyBridge\Tests\TestCase;

class VerifyShopifyWebhookSignatureTest extends TestCase
{
    protected function validPayload(): string
    {
        return json_encode(require __DIR__.'/../Fixtures/shopify_order.php');
    }

    protected function hmacFor(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, 'test-webhook-secret', true));
    }

    public function test_it_accepts_a_request_with_a_valid_signature(): void
    {
        Queue::fake();

        $body = $this->validPayload();

        $response = $this->call(
            'POST',
            '/webhook/order',
            server: ['HTTP_X-Shopify-Hmac-Sha256' => $this->hmacFor($body)],
            content: $body,
        );

        $response->assertOk();
        Queue::assertPushed(ExportOrderToValidusJob::class);
    }

    public function test_it_rejects_a_request_with_an_invalid_signature(): void
    {
        Queue::fake();

        $response = $this->call(
            'POST',
            '/webhook/order',
            server: ['HTTP_X-Shopify-Hmac-Sha256' => 'not-the-right-signature'],
            content: $this->validPayload(),
        );

        $response->assertStatus(403);
        Queue::assertNotPushed(ExportOrderToValidusJob::class);
    }

    public function test_it_rejects_a_request_with_no_signature_header(): void
    {
        Queue::fake();

        $response = $this->call('POST', '/webhook/order', content: $this->validPayload());

        $response->assertStatus(403);
        Queue::assertNotPushed(ExportOrderToValidusJob::class);
    }
}
