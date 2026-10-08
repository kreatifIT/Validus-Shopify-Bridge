<?php

namespace Kreatif\ValidusShopifyBridge\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Kreatif\ValidusShopifyBridge\Events\OrderExportFailed;
use Kreatif\ValidusShopifyBridge\Jobs\ExportOrderToValidusJob;
use Kreatif\ValidusShopifyBridge\Models\ExportedOrder;
use Kreatif\ValidusShopifyBridge\Models\ProductMap;
use Kreatif\ValidusShopifyBridge\Tests\TestCase;
use RuntimeException;

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
            'shopify_variant_id' => 'gid://shopify/ProductVariant/424242',
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
        $this->assertSame('#A2', ExportedOrder::query()->where('shopify_order_id', '5551234')->value('shopify_order_number'));
    }

    public function test_an_order_without_a_number_is_recorded_without_one(): void
    {
        $this->mapTheFixtureLineItem();

        Http::fake([
            'validus.test/*' => Http::response(['success' => true], 200),
        ]);

        $order = $this->order();
        unset($order['name']);

        (new ExportOrderToValidusJob($order))->handle(
            app(\Kreatif\ValidusShopifyBridge\Services\OrderExportService::class),
            app(\Kreatif\ValidusShopifyBridge\Clients\ValidusClient::class),
        );

        $this->assertTrue(ExportedOrder::alreadyExported('5551234'));
        $this->assertNull(ExportedOrder::query()->where('shopify_order_id', '5551234')->value('shopify_order_number'));
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

    public function test_it_fires_an_order_export_failed_event_carrying_the_order_number(): void
    {
        Event::fake();

        (new ExportOrderToValidusJob($this->order()))->failed(new RuntimeException('boom'));

        Event::assertDispatched(OrderExportFailed::class, fn (OrderExportFailed $event) => $event->shopifyOrderId === '5551234'
            && $event->orderNumber === '#A2'
            && $event->exception->getMessage() === 'boom');
    }
}
