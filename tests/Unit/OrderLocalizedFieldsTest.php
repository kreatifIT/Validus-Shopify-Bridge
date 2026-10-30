<?php

namespace Kreatif\ValidusShopifyBridge\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Kreatif\ValidusShopifyBridge\Exceptions\ShopifyApiException;
use Kreatif\ValidusShopifyBridge\Shopify\OrderLocalizedFields;
use Kreatif\ValidusShopifyBridge\Tests\TestCase;

class OrderLocalizedFieldsTest extends TestCase
{
    protected function italianOrder(): array
    {
        $order = require __DIR__.'/../Fixtures/shopify_order.php';
        $order['billing_address']['country_code'] = 'IT';

        return $order;
    }

    protected function fields(): OrderLocalizedFields
    {
        return app(OrderLocalizedFields::class);
    }

    public function test_an_italian_order_gets_its_filled_checkout_fields(): void
    {
        Http::fake(['test-shop.myshopify.com/*' => Http::response(['data' => ['order' => ['localizedFields' => ['nodes' => [
            ['key' => 'TAX_CREDENTIAL_IT', 'value' => 'RSSMRA80A01H501U'],
            ['key' => 'SHIPPING_CREDENTIAL_IT', 'value' => ''],
        ]]]]])]);

        $order = $this->fields()->addTo($this->italianOrder());

        $this->assertSame(['TAX_CREDENTIAL_IT' => 'RSSMRA80A01H501U'], $order[OrderLocalizedFields::PAYLOAD_KEY]);
        Http::assertSent(fn ($request) => $request['variables'] === ['id' => 'gid://shopify/Order/5551234']
            && str_contains($request['query'], 'localizedFields'));
    }

    public function test_the_webhooks_own_graphql_id_is_used_when_present(): void
    {
        Http::fake(['test-shop.myshopify.com/*' => Http::response(['data' => ['order' => ['localizedFields' => ['nodes' => []]]]])]);

        $order = $this->italianOrder();
        $order['admin_graphql_api_id'] = 'gid://shopify/Order/777';

        $this->assertSame([], $this->fields()->addTo($order)[OrderLocalizedFields::PAYLOAD_KEY]);
        Http::assertSent(fn ($request) => $request['variables'] === ['id' => 'gid://shopify/Order/777']);
    }

    /** Every other order stays independent of an extra Shopify call. */
    public function test_an_order_from_another_country_is_left_alone(): void
    {
        Http::fake();
        $order = require __DIR__.'/../Fixtures/shopify_order.php';

        $this->assertSame($order, $this->fields()->addTo($order));
        Http::assertNothingSent();
    }

    public function test_fields_already_on_the_order_are_not_looked_up_again(): void
    {
        Http::fake();
        $order = $this->italianOrder();
        $order[OrderLocalizedFields::PAYLOAD_KEY] = ['TAX_CREDENTIAL_IT' => 'RSSMRA80A01H501U'];

        $this->assertSame($order, $this->fields()->addTo($order));
        Http::assertNothingSent();
    }

    public function test_a_failed_lookup_throws_so_the_export_is_retried(): void
    {
        Http::fake(['test-shop.myshopify.com/*' => Http::response(['errors' => [['message' => 'Throttled']]])]);

        $this->expectException(ShopifyApiException::class);

        $this->fields()->addTo($this->italianOrder());
    }
}
