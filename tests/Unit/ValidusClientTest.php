<?php

namespace Kreatif\ValidusShopifyBridge\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Kreatif\ValidusShopifyBridge\Clients\ValidusClient;
use Kreatif\ValidusShopifyBridge\Exceptions\ValidusApiException;
use Kreatif\ValidusShopifyBridge\Tests\TestCase;

class ValidusClientTest extends TestCase
{
    protected function client(): ValidusClient
    {
        return new ValidusClient('https://validus.test/ecommerce_bridge', 'test-api-key');
    }

    public function test_get_products_returns_the_products_array(): void
    {
        Http::fake([
            'validus.test/*' => Http::response([
                'success' => true,
                'products' => [['id' => 1], ['id' => 2]],
            ]),
        ]);

        $products = $this->client()->getProducts();

        $this->assertCount(2, $products);
        Http::assertSent(fn ($request) => $request->hasHeader('X-API-KEY', 'test-api-key')
            && $request->url() === 'https://validus.test/ecommerce_bridge/products');
    }

    public function test_get_products_throws_on_a_non_2xx_response(): void
    {
        Http::fake(['validus.test/*' => Http::response(['success' => false, 'message' => 'Chiave API formalmente errata'], 400)]);

        $this->expectException(ValidusApiException::class);

        $this->client()->getProducts();
    }

    public function test_get_products_throws_when_success_is_false_despite_a_2xx_status(): void
    {
        Http::fake(['validus.test/*' => Http::response(['success' => false, 'message' => 'unexpected'], 200)]);

        $this->expectException(ValidusApiException::class);

        $this->client()->getProducts();
    }

    public function test_create_order_posts_the_payload(): void
    {
        Http::fake(['validus.test/*' => Http::response(['success' => true])]);

        $this->client()->createOrder(['orderId' => '123']);

        Http::assertSent(fn ($request) => $request->url() === 'https://validus.test/ecommerce_bridge/orders'
            && $request['orderId'] === '123');
    }

    public function test_create_order_throws_on_a_duplicate_order_id(): void
    {
        Http::fake(['validus.test/*' => Http::response(['success' => false, 'message' => 'orderId già acquisito in precedenza'], 400)]);

        $this->expectException(ValidusApiException::class);

        $this->client()->createOrder(['orderId' => '123']);
    }
}
