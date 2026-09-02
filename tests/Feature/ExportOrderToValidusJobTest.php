<?php

namespace Kreatif\ValidusShopifyBridge\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Kreatif\ValidusShopifyBridge\Jobs\ExportOrderToValidusJob;
use Kreatif\ValidusShopifyBridge\Models\ExportedOrder;
use Kreatif\ValidusShopifyBridge\Models\ProductMap;
use Kreatif\ValidusShopifyBridge\Tests\TestCase;

class ExportOrderToValidusJobTest extends TestCase
{
    protected function order(): array
    {
        return require __DIR__.'/../Fixtures/shopify_order.php';
    }

    protected function mapTheFixtureLineItem(): void
    {
        ProductMap::query()->create([
            'validus_id' => '101512',
            'validus_code' => '99070121',
            'shopify_variant_id' => '424242',
        ]);
    }

    public function test_it_sends_the_order_to_validus_and_records_it_as_exported(): void
    {
        $this->mapTheFixtureLineItem();

        Http::fake([
            'validus.test/*' => Http::response(['success' => true], 200),
        ]);

        (new ExportOrderToValidusJob($this->order()))->handle(
            app(\Kreatif\ValidusShopifyBridge\Services\OrderExportService::class),
            app(\Kreatif\ValidusShopifyBridge\Clients\ValidusClient::class),
        );

        Http::assertSent(fn ($request) => $request->url() === 'https://validus.test/ecommerce_bridge/orders'
            && $request['orderId'] === '5551234');

        $this->assertTrue(ExportedOrder::alreadyExported('5551234'));
    }

    public function test_it_does_not_send_the_same_order_twice(): void
    {
        $this->mapTheFixtureLineItem();

        ExportedOrder::query()->create([
            'shopify_order_id' => '5551234',
            'exported_at' => now(),
        ]);

        Http::fake();

        (new ExportOrderToValidusJob($this->order()))->handle(
            app(\Kreatif\ValidusShopifyBridge\Services\OrderExportService::class),
            app(\Kreatif\ValidusShopifyBridge\Clients\ValidusClient::class),
        );

        Http::assertNothingSent();
    }
}
