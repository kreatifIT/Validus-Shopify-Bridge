<?php

namespace Kreatif\ValidusShopifyBridge\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    public function test_it_writes_the_raw_webhook_payload_to_the_configured_disk(): void
    {
        config(['validus-shopify.order_export.webhook_log_disk' => 'local']);
        Storage::fake('local');
        Queue::fake();

        $body = $this->validPayload();

        $this->call('POST', '/webhook/order', server: ['HTTP_X-Shopify-Hmac-Sha256' => $this->hmacFor($body)], content: $body);

        Storage::disk('local')->assertExists('validus-order-webhooks/5551234.json');
        $written = json_decode(Storage::disk('local')->get('validus-order-webhooks/5551234.json'), true);
        $this->assertSame('#A2', $written['name']);
    }

    public function test_it_overwrites_the_webhook_payload_file_on_a_redelivery(): void
    {
        config(['validus-shopify.order_export.webhook_log_disk' => 'local']);
        Storage::fake('local');
        Storage::disk('local')->put('validus-order-webhooks/5551234.json', 'stale delivery');
        Queue::fake();

        $body = $this->validPayload();

        $this->call('POST', '/webhook/order', server: ['HTTP_X-Shopify-Hmac-Sha256' => $this->hmacFor($body)], content: $body);

        $written = json_decode(Storage::disk('local')->get('validus-order-webhooks/5551234.json'), true);
        $this->assertSame('5551234', (string) $written['id']);
    }

    public function test_it_writes_nothing_when_the_webhook_log_disk_is_not_configured(): void
    {
        config(['validus-shopify.order_export.webhook_log_disk' => null]);
        Storage::fake('local');
        Queue::fake();

        $body = $this->validPayload();

        $this->call('POST', '/webhook/order', server: ['HTTP_X-Shopify-Hmac-Sha256' => $this->hmacFor($body)], content: $body);

        Storage::disk('local')->assertDirectoryEmpty('validus-order-webhooks');
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
