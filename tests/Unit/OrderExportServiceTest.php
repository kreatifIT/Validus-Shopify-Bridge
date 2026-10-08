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
            'shopify_variant_id' => 'gid://shopify/ProductVariant/424242',
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

    public function test_an_order_without_a_company_name_is_reported_as_a_private_person(): void
    {
        ProductMap::query()->create([
            'validus_id' => '101512',
            'validus_code' => '99070121',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/424242',
        ]);

        $service = new OrderExportService(['shopify_payments' => 'CC']);

        $payload = $service->buildPayload($this->order());

        $this->assertSame('person', $payload['customer']['type']);
        $this->assertNull($payload['customer']['companyName']);
    }

    /**
     * Validus rejects a company without a VAT number, and Shopify's checkout
     * collects none - the company only goes on the shipping address.
     */
    public function test_a_company_without_a_vat_number_is_reported_as_a_private_person(): void
    {
        $order = $this->order();
        // Shopify's standard checkout has no B2B toggle - a business
        // customer just fills the free-text "Company" field.
        $order['billing_address']['company'] = 'Ristorante Da Mario';
        $order['shipping_address']['company'] = 'Ristorante Da Mario';

        $customer = $this->service()->buildPayload($order)['customer'];

        $this->assertSame('person', $customer['type']);
        $this->assertNull($customer['companyName']);
        $this->assertNull($customer['vatNumber']);
        $this->assertSame('Hans', $customer['firstName']);
        $this->assertSame('Müller', $customer['lastName']);
        $this->assertSame('Tölzer Straße 15', $customer['billingAddress']['street']);
        $this->assertSame('Ristorante Da Mario, Industriestraße 8', $customer['shippingAddress']['street']);
    }

    public function test_a_company_only_on_the_billing_address_does_not_end_up_on_the_shipping_address(): void
    {
        $order = $this->order();
        $order['billing_address']['company'] = 'Ristorante Da Mario';
        $order['shipping_address']['company'] = '';

        $customer = $this->service()->buildPayload($order)['customer'];

        $this->assertSame('person', $customer['type']);
        $this->assertSame('Industriestraße 8', $customer['shippingAddress']['street']);
    }

    public function test_a_company_with_a_vat_number_is_reported_as_a_company(): void
    {
        ProductMap::query()->firstOrCreate(['shopify_variant_id' => 'gid://shopify/ProductVariant/424242'], [
            'validus_id' => '101512',
            'validus_code' => '99070121',
        ]);

        $service = new class(['shopify_payments' => 'CC']) extends OrderExportService
        {
            protected function vatNumber(array $shopifyOrder): ?string
            {
                return 'IT01234567890';
            }
        };

        $order = $this->order();
        $order['billing_address']['company'] = 'Ristorante Da Mario';
        $order['shipping_address']['company'] = 'Ristorante Da Mario';

        $customer = $service->buildPayload($order)['customer'];

        $this->assertSame('company', $customer['type']);
        $this->assertSame('Ristorante Da Mario', $customer['companyName']);
        $this->assertSame('IT01234567890', $customer['vatNumber']);
        $this->assertSame('Ristorante Da Mario, Industriestraße 8', $customer['shippingAddress']['street']);
    }

    public function test_a_vat_number_without_a_company_name_stays_a_private_person(): void
    {
        ProductMap::query()->firstOrCreate(['shopify_variant_id' => 'gid://shopify/ProductVariant/424242'], [
            'validus_id' => '101512',
            'validus_code' => '99070121',
        ]);

        $service = new class(['shopify_payments' => 'CC']) extends OrderExportService
        {
            protected function vatNumber(array $shopifyOrder): ?string
            {
                return 'IT01234567890';
            }
        };

        $customer = $service->buildPayload($this->order())['customer'];

        $this->assertSame('person', $customer['type']);
        $this->assertNull($customer['vatNumber']);
    }

    public function test_the_phone_number_is_taken_from_the_billing_address_when_order_and_customer_have_none(): void
    {
        $order = $this->checkoutOrder();
        $order['billing_address']['phone'] = '340 1234567';
        $order['shipping_address']['phone'] = '+39 333 7654321';

        $payload = $this->service()->buildPayload($order);

        $this->assertSame('340 1234567', $payload['customer']['phone']);
    }

    public function test_the_phone_number_is_taken_from_the_shipping_address_when_the_billing_address_has_none(): void
    {
        $order = $this->checkoutOrder();
        $order['billing_address']['phone'] = null;
        $order['shipping_address']['phone'] = '0160 1234567';

        $payload = $this->service()->buildPayload($order);

        $this->assertSame('0160 1234567', $payload['customer']['phone']);
    }

    public function test_the_order_phone_number_comes_first(): void
    {
        $order = $this->order();
        $order['billing_address']['phone'] = '340 1234567';

        $payload = $this->service()->buildPayload($order);

        $this->assertSame('+49 30 12345678', $payload['customer']['phone']);
    }

    public function test_an_order_without_any_phone_number_reports_none(): void
    {
        $payload = $this->service()->buildPayload($this->checkoutOrder());

        $this->assertNull($payload['customer']['phone']);
    }

    public function test_the_email_falls_back_to_the_contact_email_and_then_the_customer(): void
    {
        $order = $this->checkoutOrder();
        $order['email'] = null;
        $order['contact_email'] = 'contact@muster.de';

        $this->assertSame('contact@muster.de', $this->service()->buildPayload($order)['customer']['email']);

        $order['contact_email'] = '';

        $this->assertSame('h.mueller@muster.de', $this->service()->buildPayload($order)['customer']['email']);
    }

    public function test_the_name_falls_back_to_the_billing_address_when_the_customer_has_none(): void
    {
        $order = $this->checkoutOrder();
        $order['customer']['first_name'] = null;
        $order['customer']['last_name'] = '';

        $payload = $this->service()->buildPayload($order);

        $this->assertSame('Hans', $payload['customer']['firstName']);
        $this->assertSame('Müller', $payload['customer']['lastName']);
    }

    /**
     * The fixture with Shopify's empty fields as a real checkout sends them.
     *
     * @return array<string, mixed>
     */
    protected function checkoutOrder(): array
    {
        $order = $this->order();
        $order['phone'] = null;
        $order['customer']['phone'] = null;

        return $order;
    }

    protected function service(): OrderExportService
    {
        ProductMap::query()->firstOrCreate(['shopify_variant_id' => 'gid://shopify/ProductVariant/424242'], [
            'validus_id' => '101512',
            'validus_code' => '99070121',
        ]);

        return new OrderExportService(['shopify_payments' => 'CC']);
    }

    public function test_it_refuses_to_build_a_payload_for_an_unmapped_line_item(): void
    {
        // No ProductMap row created - simulates a Shopify product that was
        // never imported from Validus.
        $service = new OrderExportService(['shopify_payments' => 'CC']);

        try {
            $service->buildPayload($this->order());
            $this->fail('Expected a MissingProductMappingException.');
        } catch (MissingProductMappingException $e) {
            // The raw Shopify variant id alone isn't enough to know what
            // product this even is - the message needs to carry the
            // product/variant name and SKU straight from the fixture's
            // line item, not just [424242].
            $this->assertStringContainsString('Demo Reserve 2021 - 0,75l', $e->getMessage());
            $this->assertStringContainsString('99070121', $e->getMessage());
            $this->assertStringContainsString('424242', $e->getMessage());
        }
    }

    public function test_it_computes_a_line_items_discount_percent_from_shopifys_discount_allocations(): void
    {
        ProductMap::query()->create([
            'validus_id' => '101512',
            'validus_code' => '99070121',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/424242',
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
            'shopify_variant_id' => 'gid://shopify/ProductVariant/424242',
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
            'shopify_variant_id' => 'gid://shopify/ProductVariant/424242',
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
            'shopify_variant_id' => 'gid://shopify/ProductVariant/424242',
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
            'shopify_variant_id' => 'gid://shopify/ProductVariant/424242',
        ]);

        $service = new OrderExportService([]); // no gateway mapped

        $this->expectException(\RuntimeException::class);

        $service->buildPayload($this->order());
    }
}
