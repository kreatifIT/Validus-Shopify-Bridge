<?php

namespace Kreatif\ValidusShopifyBridge\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Kreatif\ValidusShopifyBridge\Exceptions\ShopifyApiException;
use Kreatif\ValidusShopifyBridge\Shopify\ShopifyGraphqlClient;
use Kreatif\ValidusShopifyBridge\Tests\TestCase;

class ShopifyGraphqlClientTest extends TestCase
{
    protected function client(): ShopifyGraphqlClient
    {
        return new ShopifyGraphqlClient('test-shop.myshopify.com', 'shpat_test-token', '2025-04');
    }

    public function test_it_posts_the_query_to_the_correct_endpoint_with_the_access_token_header(): void
    {
        Http::fake([
            'test-shop.myshopify.com/*' => Http::response(['data' => ['shop' => ['name' => 'Test Shop']]]),
        ]);

        $data = $this->client()->query('query { shop { name } }', ['foo' => 'bar']);

        $this->assertSame(['shop' => ['name' => 'Test Shop']], $data);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://test-shop.myshopify.com/admin/api/2025-04/graphql.json'
                && $request->hasHeader('X-Shopify-Access-Token', 'shpat_test-token')
                && $request['variables'] === ['foo' => 'bar'];
        });
    }

    public function test_it_throws_on_a_non_2xx_response(): void
    {
        Http::fake(['test-shop.myshopify.com/*' => Http::response('Unauthorized', 401)]);

        $this->expectException(ShopifyApiException::class);

        $this->client()->query('query { shop { name } }');
    }

    public function test_it_throws_on_top_level_graphql_errors(): void
    {
        Http::fake(['test-shop.myshopify.com/*' => Http::response([
            'errors' => [['message' => 'Field "bogus" doesn\'t exist']],
        ])]);

        $this->expectException(ShopifyApiException::class);

        $this->client()->query('query { bogus }');
    }
}
