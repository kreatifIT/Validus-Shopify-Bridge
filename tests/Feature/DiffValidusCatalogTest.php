<?php

namespace Kreatif\ValidusShopifyBridge\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Kreatif\ValidusShopifyBridge\Models\ProductMap;
use Kreatif\ValidusShopifyBridge\Shopify\ProductWriter;
use Kreatif\ValidusShopifyBridge\Tests\TestCase;

class DiffValidusCatalogTest extends TestCase
{
    protected function fakeValidusProductsEndpoint(): void
    {
        Http::fake([
            'validus.test/*' => Http::response(['success' => true, 'products' => [
                // Not mapped yet, but already exists in Shopify under the same SKU -> conflict.
                [
                    'id' => 1001,
                    'name' => 'Demo Wine',
                    'code' => ['code' => '56070025', 'year' => 2025, 'bottleCapacity' => 0.75],
                    'price' => ['fullPrice' => 18.0],
                ],
                // Mapped, and Shopify's price still matches -> unchanged.
                [
                    'id' => 1002,
                    'name' => 'Mapped Wine',
                    'code' => ['code' => '11111111', 'year' => 2025, 'bottleCapacity' => 0.75],
                    'price' => ['fullPrice' => 20.0],
                ],
                // Mapped, but Shopify's price is stale -> price changed.
                [
                    'id' => 1003,
                    'name' => 'Repriced Wine',
                    'code' => ['code' => '22222222', 'year' => 2025, 'bottleCapacity' => 0.75],
                    'price' => ['fullPrice' => 25.0],
                ],
                // No SKU match anywhere -> truly new.
                [
                    'id' => 1004,
                    'name' => 'Brand New Wine',
                    'code' => ['code' => '33333333', 'year' => 2025, 'bottleCapacity' => 0.75],
                    'price' => ['fullPrice' => 12.0],
                ],
            ]]),
        ]);
    }

    public function test_it_separates_conflicts_new_and_changed_variants(): void
    {
        $this->fakeValidusProductsEndpoint();

        ProductMap::query()->create([
            'validus_id' => '1002',
            'validus_code' => '11111111',
            'shopify_product_id' => 'gid://shopify/Product/2',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/2',
        ]);
        ProductMap::query()->create([
            'validus_id' => '1003',
            'validus_code' => '22222222',
            'shopify_product_id' => 'gid://shopify/Product/3',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/3',
        ]);

        $this->mock(ProductWriter::class, function ($mock) {
            $mock->shouldReceive('variantsBySku')
                ->once()
                ->with(['56070025', '11111111', '22222222', '33333333'])
                ->andReturn([
                    '56070025' => [
                        'id' => 'gid://shopify/ProductVariant/1',
                        'price' => '18.00',
                        'productId' => 'gid://shopify/Product/1',
                        'productTitle' => 'Demo Wine',
                    ],
                    '11111111' => [
                        'id' => 'gid://shopify/ProductVariant/2',
                        'price' => '20.00',
                        'productId' => 'gid://shopify/Product/2',
                        'productTitle' => 'Mapped Wine',
                    ],
                    '22222222' => [
                        'id' => 'gid://shopify/ProductVariant/3',
                        'price' => '19.00',
                        'productId' => 'gid://shopify/Product/3',
                        'productTitle' => 'Repriced Wine',
                    ],
                ]);
        });

        $this->artisan('validus-shopify:diff')
            ->expectsOutputToContain('FOUND IN SHOPIFY BUT NOT LINKED')
            ->expectsOutputToContain('Summary: 1 found but unlinked, 1 truly new, 1 linked with a price change, 1 linked and unchanged (4 variants total).')
            ->assertExitCode(0);
    }

    public function test_all_option_also_lists_unchanged_variants(): void
    {
        $this->fakeValidusProductsEndpoint();

        ProductMap::query()->create([
            'validus_id' => '1002',
            'validus_code' => '11111111',
            'shopify_product_id' => 'gid://shopify/Product/2',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/2',
        ]);
        ProductMap::query()->create([
            'validus_id' => '1003',
            'validus_code' => '22222222',
            'shopify_product_id' => 'gid://shopify/Product/3',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/3',
        ]);

        $this->mock(ProductWriter::class, function ($mock) {
            $mock->shouldReceive('variantsBySku')->once()->andReturn([
                '11111111' => [
                    'id' => 'gid://shopify/ProductVariant/2',
                    'price' => '20.00',
                    'productId' => 'gid://shopify/Product/2',
                    'productTitle' => 'Mapped Wine',
                ],
            ]);
        });

        $this->artisan('validus-shopify:diff', ['--all' => true])
            ->expectsOutputToContain('UNCHANGED (already linked) (1)')
            ->assertExitCode(0);
    }
}
