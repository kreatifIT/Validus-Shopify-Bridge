<?php

namespace Kreatif\ValidusShopifyBridge\Tests\Unit;

use Kreatif\ValidusShopifyBridge\Exceptions\MissingProductMappingException;
use Kreatif\ValidusShopifyBridge\Models\ProductMap;
use Kreatif\ValidusShopifyBridge\Services\OrderExportService;
use Kreatif\ValidusShopifyBridge\Tests\TestCase;

class OrderExportServiceTest extends TestCase
{
    protected function order(): array
    {
        return require __DIR__.'/../Fixtures/shopify_order.php';
    }

    public function test_it_maps_a_shopify_order_to_the_validus_payload_shape(): void
    {
        ProductMap::query()->create([
            'validus_id' => '101512',
            'validus_code' => '99070121',
            'shopify_variant_id' => '424242',
        ]);

        $service = new OrderExportService(['shopify_payments' => 'CC']);

        $payload = $service->buildPayload($this->order());

        $this->assertSame('5551234', $payload['orderId']);
        $this->assertSame('2025-06-29', $payload['orderDate']);
        $this->assertSame('confirmed', $payload['status']);
        $this->assertSame('EUR', $payload['currency']);

        $this->assertSame('Hans', $payload['customer']['firstName']);
        $this->assertSame('Müller', $payload['customer']['lastName']);
        $this->assertSame('person', $payload['customer']['type']);
        $this->assertSame('DE', $payload['customer']['billingAddress']['countryCode']);
        $this->assertSame('Tölzer Straße 15', $payload['customer']['billingAddress']['street']);

        $this->assertCount(1, $payload['items']);
        $this->assertSame(101512, $payload['items'][0]['productId']);
        $this->assertSame('99070121', $payload['items'][0]['code']);
        $this->assertSame(2, $payload['items'][0]['quantity']);
        $this->assertSame(22.0, $payload['items'][0]['vatRate']);

        $this->assertSame(20.0, $payload['shipping']['shippingCost']);
        $this->assertSame(45.0, $payload['grandTotal']);

        $this->assertCount(1, $payload['payments']);
        $this->assertSame('CC', $payload['payments'][0]['paymentCode']);

        $this->assertCount(1, $payload['taxBreakdown']);
        $this->assertSame(22.0, $payload['taxBreakdown'][0]['vat']);
        $this->assertSame(5.5, $payload['taxBreakdown'][0]['tax']);
    }

    public function test_it_refuses_to_build_a_payload_for_an_unmapped_line_item(): void
    {
        // No ProductMap row created - simulates a Shopify product that was
        // never imported from Validus.
        $service = new OrderExportService(['shopify_payments' => 'CC']);

        $this->expectException(MissingProductMappingException::class);

        $service->buildPayload($this->order());
    }

    public function test_it_refuses_to_build_a_payload_for_an_unconfigured_payment_gateway(): void
    {
        ProductMap::query()->create([
            'validus_id' => '101512',
            'validus_code' => '99070121',
            'shopify_variant_id' => '424242',
        ]);

        $service = new OrderExportService([]); // no gateway mapped

        $this->expectException(\RuntimeException::class);

        $service->buildPayload($this->order());
    }
}
