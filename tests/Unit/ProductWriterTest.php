<?php

namespace Kreatif\ValidusShopifyBridge\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Kreatif\ValidusShopifyBridge\Exceptions\ShopifyApiException;
use Kreatif\ValidusShopifyBridge\Shopify\ProductWriter;
use Kreatif\ValidusShopifyBridge\Shopify\ShopifyGraphqlClient;
use Kreatif\ValidusShopifyBridge\Tests\TestCase;

class ProductWriterTest extends TestCase
{
    protected function writer(): ProductWriter
    {
        return new ProductWriter(new ShopifyGraphqlClient('test-shop.myshopify.com', 'shpat_test-token', '2025-04'));
    }

    public function test_upsert_product_sends_a_productSet_mutation_and_returns_the_variants(): void
    {
        Http::fake([
            'test-shop.myshopify.com/*' => Http::response(['data' => [
                'productSet' => [
                    'product' => [
                        'id' => 'gid://shopify/Product/1',
                        'variants' => ['nodes' => [
                            ['id' => 'gid://shopify/ProductVariant/1', 'sku' => '56070025'],
                        ]],
                    ],
                    'userErrors' => [],
                ],
            ]]),
        ]);

        $result = $this->writer()->upsertProduct([
            'title' => 'Demo Wine',
            'options' => [['name' => 'Vintage', 'values' => ['2025']]],
            'variants' => [['sku' => '56070025', 'price' => '18.00']],
            'shopifyProductId' => null,
        ]);

        $this->assertSame('gid://shopify/Product/1', $result['productId']);
        $this->assertSame('56070025', $result['variants'][0]['sku']);

        Http::assertSent(function ($request) {
            return str_contains($request['query'], 'productSet')
                && $request['variables']['input']['title'] === 'Demo Wine'
                && ! isset($request['variables']['input']['id']);
        });
    }

    public function test_upsert_product_includes_the_existing_product_id_when_updating(): void
    {
        Http::fake([
            'test-shop.myshopify.com/*' => Http::response(['data' => [
                'productSet' => ['product' => ['id' => 'gid://shopify/Product/1', 'variants' => ['nodes' => []]], 'userErrors' => []],
            ]]),
        ]);

        $this->writer()->upsertProduct([
            'title' => 'Demo Wine',
            'options' => [],
            'variants' => [],
            'shopifyProductId' => 'gid://shopify/Product/1',
        ]);

        Http::assertSent(fn ($request) => $request['variables']['input']['id'] === 'gid://shopify/Product/1');
    }

    public function test_upsert_product_throws_on_user_errors(): void
    {
        Http::fake([
            'test-shop.myshopify.com/*' => Http::response(['data' => [
                'productSet' => ['product' => null, 'userErrors' => [['field' => ['title'], 'message' => 'Title required']]],
            ]]),
        ]);

        $this->expectException(ShopifyApiException::class);

        $this->writer()->upsertProduct([
            'title' => '',
            'options' => [],
            'variants' => [],
            'shopifyProductId' => null,
        ]);
    }

    public function test_variants_by_sku_returns_matches_keyed_by_sku(): void
    {
        Http::fake([
            'test-shop.myshopify.com/*' => Http::response(['data' => [
                'productVariants' => ['nodes' => [
                    [
                        'id' => 'gid://shopify/ProductVariant/1',
                        'sku' => '56070025',
                        'price' => '18.00',
                        'product' => ['id' => 'gid://shopify/Product/1', 'title' => 'Demo Wine'],
                    ],
                ]],
            ]]),
        ]);

        $result = $this->writer()->variantsBySku(['56070025', '99999999']);

        $this->assertSame([
            '56070025' => [
                'id' => 'gid://shopify/ProductVariant/1',
                'price' => '18.00',
                'productId' => 'gid://shopify/Product/1',
                'productTitle' => 'Demo Wine',
            ],
        ], $result);

        Http::assertSent(fn ($request) => str_contains($request['variables']['q'], 'sku:56070025')
            && str_contains($request['variables']['q'], 'sku:99999999'));
    }

    public function test_variants_by_sku_returns_empty_array_without_calling_shopify(): void
    {
        Http::fake();

        $this->assertSame([], $this->writer()->variantsBySku([]));

        Http::assertNothingSent();
    }

    public function test_variants_by_sku_chunks_large_sku_lists(): void
    {
        Http::fake([
            'test-shop.myshopify.com/*' => Http::response(['data' => [
                'productVariants' => ['nodes' => []],
            ]]),
        ]);

        $skus = array_map(fn (int $i) => (string) (10000000 + $i), range(1, 45));

        $this->writer()->variantsBySku($skus);

        Http::assertSentCount(3);
    }
}
