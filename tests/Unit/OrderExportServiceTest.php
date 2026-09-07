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

    public function test_it_computes_a_line_items_discount_percent_from_shopifys_discount_allocations(): void
    {
        ProductMap::query()->create([
            'validus_id' => '101512',
            'validus_code' => '99070121',
            'shopify_variant_id' => '424242',
        ]);

        $order = $this->order();
        // Fixture line item: price 12.50 * quantity 2 = 25.00 gross;
        // a 5.00 discount allocation is a 20% line-level discount.
        $order['line_items'][0]['discount_allocations'] = [
            ['amount' => '5.00', 'discount_application_index' => 0],
        ];

        $service = new OrderExportService(['shopify_payments' => 'CC']);

        $payload = $service->buildPayload($order);

        $this->assertSame(20.0, $payload['items'][0]['discountPercent']);
        // unitPriceNet stays the pre-discount price - discountPercent is
        // what tells Validus how much of it to take off.
        $this->assertSame(12.5, $payload['items'][0]['unitPriceNet']);
    }

    public function test_a_cart_wide_discount_is_reported_as_its_own_position_instead_of_reducing_product_lines(): void
    {
        ProductMap::query()->create([
            'validus_id' => '101512',
            'validus_code' => '99070121',
            'shopify_variant_id' => '424242',
        ]);

        $order = $this->order();
        // 10% off the whole cart via a discount code - Shopify still
        // allocates it down to each line item, but target_selection "all"
        // marks it as a cart-wide discount rather than a product-specific one.
        $order['discount_applications'] = [
            ['type' => 'discount_code', 'code' => 'SUMMER10', 'value' => '10.0', 'value_type' => 'percentage', 'allocation_method' => 'across', 'target_selection' => 'all', 'target_type' => 'line_item'],
        ];
        $order['line_items'][0]['discount_allocations'] = [
            ['amount' => '2.50', 'discount_application_index' => 0],
        ];

        $service = new OrderExportService(['shopify_payments' => 'CC']);

        $payload = $service->buildPayload($order);

        $this->assertCount(2, $payload['items']);

        // The wine itself is unaffected - the cart-wide discount is NOT
        // folded into its discountPercent.
        $this->assertSame(0.0, $payload['items'][0]['discountPercent']);
        $this->assertSame(12.5, $payload['items'][0]['unitPriceNet']);

        // The discount gets its own position instead.
        $discountItem = $payload['items'][1];
        $this->assertNull($discountItem['productId']);
        $this->assertNull($discountItem['code']);
        $this->assertSame('Rabatt: SUMMER10', $discountItem['description']);
        $this->assertSame(1, $discountItem['quantity']);
        $this->assertSame(-2.5, $discountItem['unitPriceNet']);
        $this->assertSame(22.0, $discountItem['vatRate']);
    }

    public function test_a_line_item_without_any_discount_allocations_reports_a_zero_percent_discount(): void
    {
        ProductMap::query()->create([
            'validus_id' => '101512',
            'validus_code' => '99070121',
            'shopify_variant_id' => '424242',
        ]);

        $service = new OrderExportService(['shopify_payments' => 'CC']);

        $payload = $service->buildPayload($this->order());

        $this->assertSame(0.0, $payload['items'][0]['discountPercent']);
    }

    public function test_a_line_item_without_a_shopify_variant_is_reported_without_a_product_code(): void
    {
        ProductMap::query()->create([
            'validus_id' => '101512',
            'validus_code' => '99070121',
            'shopify_variant_id' => '424242',
        ]);

        $order = $this->order();
        $order['line_items'][] = [
            'id' => 222,
            'variant_id' => null,
            'sku' => null,
            'name' => 'Geschenkgutschein',
            'quantity' => 1,
            'price' => '25.00',
            'tax_lines' => [],
        ];

        $service = new OrderExportService(['shopify_payments' => 'CC']);

        $payload = $service->buildPayload($order);

        $this->assertCount(2, $payload['items']);
        $this->assertNull($payload['items'][1]['productId']);
        $this->assertNull($payload['items'][1]['code']);
        $this->assertSame('Geschenkgutschein', $payload['items'][1]['description']);
        $this->assertSame(25.0, $payload['items'][1]['unitPriceNet']);
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
