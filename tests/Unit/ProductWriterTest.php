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

    public function test_set_inventory_quantity_turns_a_bare_numeric_location_id_into_a_gid(): void
    {
        Http::fake([
            'test-shop.myshopify.com/*' => Http::response(['data' => [
                'inventorySetQuantities' => ['userErrors' => []],
            ]]),
        ]);

        // config('validus-shopify.shopify.location_id') is human-typed, most
        // likely the bare numeric id copied from Shopify Admin's URL - unlike
        // every other id here, it never round-trips through a prior Shopify
        // response first, so nothing else would have GID-prefixed it already.
        $this->writer()->setInventoryQuantity('gid://shopify/InventoryItem/1', '123371749707', 0);

        Http::assertSent(fn ($request) => str_contains($request['query'], 'inventorySetQuantities')
            && $request['variables']['input']['quantities'][0]['locationId'] === 'gid://shopify/Location/123371749707');
    }

    public function test_set_inventory_quantity_leaves_an_already_prefixed_location_id_alone(): void
    {
        Http::fake([
            'test-shop.myshopify.com/*' => Http::response(['data' => [
                'inventorySetQuantities' => ['userErrors' => []],
            ]]),
        ]);

        $this->writer()->setInventoryQuantity('gid://shopify/InventoryItem/1', 'gid://shopify/Location/123371749707', 0);

        Http::assertSent(fn ($request) => $request['variables']['input']['quantities'][0]['locationId'] === 'gid://shopify/Location/123371749707');
    }

    public function test_set_inventory_quantity_throws_on_user_errors(): void
    {
        Http::fake([
            'test-shop.myshopify.com/*' => Http::response(['data' => [
                'inventorySetQuantities' => ['userErrors' => [['field' => ['quantities'], 'message' => 'Nope']]],
            ]]),
        ]);

        $this->expectException(ShopifyApiException::class);

        $this->writer()->setInventoryQuantity('gid://shopify/InventoryItem/1', '123371749707', 0);
    }

    public function test_set_inventory_tracked_sends_an_inventoryItemUpdate_mutation(): void
    {
        Http::fake([
            'test-shop.myshopify.com/*' => Http::response(['data' => [
                'inventoryItemUpdate' => ['userErrors' => []],
            ]]),
        ]);

        $this->writer()->setInventoryTracked('gid://shopify/InventoryItem/1');

        Http::assertSent(fn ($request) => str_contains($request['query'], 'inventoryItemUpdate')
            && $request['variables']['id'] === 'gid://shopify/InventoryItem/1'
            && $request['variables']['input']['tracked'] === true);
    }

    public function test_set_inventory_tracked_throws_on_user_errors(): void
    {
        Http::fake([
            'test-shop.myshopify.com/*' => Http::response(['data' => [
                'inventoryItemUpdate' => ['userErrors' => [['field' => ['tracked'], 'message' => 'Nope']]],
            ]]),
        ]);

        $this->expectException(ShopifyApiException::class);

        $this->writer()->setInventoryTracked('gid://shopify/InventoryItem/1');
    }

    public function test_set_variant_inventory_policy_sends_a_productVariantsBulkUpdate_mutation(): void
    {
        Http::fake([
            'test-shop.myshopify.com/*' => Http::response(['data' => [
                'productVariantsBulkUpdate' => ['userErrors' => []],
            ]]),
        ]);

        $this->writer()->setVariantInventoryPolicy('gid://shopify/Product/1', 'gid://shopify/ProductVariant/1', 'DENY');

        Http::assertSent(fn ($request) => str_contains($request['query'], 'productVariantsBulkUpdate')
            && $request['variables']['productId'] === 'gid://shopify/Product/1'
            && $request['variables']['variants'] === [['id' => 'gid://shopify/ProductVariant/1', 'inventoryPolicy' => 'DENY']]);
    }

    public function test_set_variant_inventory_policy_throws_on_user_errors(): void
    {
        Http::fake([
            'test-shop.myshopify.com/*' => Http::response(['data' => [
                'productVariantsBulkUpdate' => ['userErrors' => [['field' => ['inventoryPolicy'], 'message' => 'Nope']]],
            ]]),
        ]);

        $this->expectException(ShopifyApiException::class);

        $this->writer()->setVariantInventoryPolicy('gid://shopify/Product/1', 'gid://shopify/ProductVariant/1', 'DENY');
    }

    public function test_set_product_status_sends_a_productUpdate_mutation(): void
    {
        Http::fake([
            'test-shop.myshopify.com/*' => Http::response(['data' => [
                'productUpdate' => ['userErrors' => []],
            ]]),
        ]);

        $this->writer()->setProductStatus('gid://shopify/Product/1', 'ARCHIVED');

        Http::assertSent(fn ($request) => str_contains($request['query'], 'productUpdate')
            && $request['variables']['input'] === ['id' => 'gid://shopify/Product/1', 'status' => 'ARCHIVED']);
    }

    public function test_set_product_status_throws_on_user_errors(): void
    {
        Http::fake([
            'test-shop.myshopify.com/*' => Http::response(['data' => [
                'productUpdate' => ['userErrors' => [['field' => ['status'], 'message' => 'Nope']]],
            ]]),
        ]);

        $this->expectException(ShopifyApiException::class);

        $this->writer()->setProductStatus('gid://shopify/Product/1', 'ARCHIVED');
    }
}
