<?php

namespace Kreatif\ValidusShopifyBridge\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Kreatif\ValidusShopifyBridge\Models\ProductMap;
use Kreatif\ValidusShopifyBridge\Shopify\ProductWriter;
use Kreatif\ValidusShopifyBridge\Tests\TestCase;

class LinkExistingShopifyVariantsTest extends TestCase
{
    protected function fakeValidusProductsEndpoint(): void
    {
        Http::fake([
            'validus.test/*' => Http::response(['success' => true, 'products' => [
                [
                    'id' => 1001,
                    'name' => 'Demo Wine',
                    'code' => ['code' => '56070025', 'year' => 2025, 'bottleCapacity' => 0.75],
                    'price' => ['fullPrice' => 18.0],
                ],
                [
                    'id' => 1002,
                    'name' => 'Other Wine',
                    'code' => ['code' => '99999999', 'year' => 2025, 'bottleCapacity' => 0.75],
                    'price' => ['fullPrice' => 9.0],
                ],
            ]]),
        ]);
    }

    public function test_it_links_products_that_match_an_existing_shopify_variant_by_sku(): void
    {
        $this->fakeValidusProductsEndpoint();

        $this->mock(ProductWriter::class, function ($mock) {
            $mock->shouldReceive('variantsBySku')
                ->once()
                ->with(['56070025', '99999999'])
                ->andReturn([
                    '56070025' => [
                        'id' => 'gid://shopify/ProductVariant/1',
                        'price' => '18.00',
                        'productId' => 'gid://shopify/Product/1',
                        'productTitle' => 'Demo Wine',
                    ],
                ]);
        });

        $this->artisan('validus-shopify:link-existing')->assertExitCode(0);

        $this->assertSame(1, ProductMap::query()->count());

        $map = ProductMap::query()->first();
        $this->assertSame('1001', $map->validus_id);
        $this->assertSame('56070025', $map->validus_code);
        $this->assertSame('gid://shopify/Product/1', $map->shopify_product_id);
        $this->assertSame('gid://shopify/ProductVariant/1', $map->shopify_variant_id);
    }

    public function test_dry_run_does_not_write_any_mapping(): void
    {
        $this->fakeValidusProductsEndpoint();

        $this->mock(ProductWriter::class, function ($mock) {
            $mock->shouldReceive('variantsBySku')->once()->andReturn([
                '56070025' => [
                    'id' => 'gid://shopify/ProductVariant/1',
                    'price' => '18.00',
                    'productId' => 'gid://shopify/Product/1',
                    'productTitle' => 'Demo Wine',
                ],
            ]);
        });

        $this->artisan('validus-shopify:link-existing', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, ProductMap::query()->count());
    }

    public function test_it_skips_products_already_mapped(): void
    {
        $this->fakeValidusProductsEndpoint();

        ProductMap::query()->create([
            'validus_id' => '1001',
            'validus_code' => '56070025',
            'shopify_product_id' => 'gid://shopify/Product/1',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1',
        ]);

        $this->mock(ProductWriter::class, function ($mock) {
            $mock->shouldReceive('variantsBySku')
                ->once()
                ->with(['99999999'])
                ->andReturn([]);
        });

        $this->artisan('validus-shopify:link-existing')->assertExitCode(0);

        $this->assertSame(1, ProductMap::query()->count());
    }

    public function test_it_reports_nothing_to_link_without_calling_shopify_when_everything_is_mapped(): void
    {
        $this->fakeValidusProductsEndpoint();

        ProductMap::query()->create([
            'validus_id' => '1001',
            'validus_code' => '56070025',
            'shopify_product_id' => 'gid://shopify/Product/1',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1',
        ]);
        ProductMap::query()->create([
            'validus_id' => '1002',
            'validus_code' => '99999999',
            'shopify_product_id' => 'gid://shopify/Product/2',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/2',
        ]);

        $this->mock(ProductWriter::class, function ($mock) {
            $mock->shouldNotReceive('variantsBySku');
        });

        $this->artisan('validus-shopify:link-existing')
            ->expectsOutputToContain('Nothing to link')
            ->assertExitCode(0);
    }
}
