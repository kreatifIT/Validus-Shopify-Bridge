<?php

namespace Kreatif\ValidusShopifyBridge\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Kreatif\ValidusShopifyBridge\Clients\ValidusClient;
use Kreatif\ValidusShopifyBridge\Grouping\ProductCodeGroupingStrategy;
use Kreatif\ValidusShopifyBridge\Models\ProductMap;
use Kreatif\ValidusShopifyBridge\Services\ProductSyncService;
use Kreatif\ValidusShopifyBridge\Shopify\ProductWriter;
use Kreatif\ValidusShopifyBridge\Tests\TestCase;
use Mockery;

class ProductSyncServiceTest extends TestCase
{
    protected function validusProducts(): array
    {
        return [
            [
                'id' => 1001,
                'name' => 'Demo Wine',
                'code' => ['code' => '56070025', 'year' => 2025, 'bottleCapacity' => 0.75, 'measureUnit' => 'pz'],
                'price' => ['fullPrice' => 18.0, 'discountedPrice' => 18.0],
                'tax' => ['rate' => 22],
                'qtyInStock' => 100,
            ],
            [
                'id' => 1002,
                'name' => 'Demo Wine',
                'code' => ['code' => '56090125', 'year' => 2025, 'bottleCapacity' => 1.5, 'measureUnit' => 'pz'],
                'price' => ['fullPrice' => 38.0, 'discountedPrice' => 38.0],
                'tax' => ['rate' => 22],
                'qtyInStock' => 20,
            ],
        ];
    }

    protected function fakeValidusProductsEndpoint(): void
    {
        Http::fake([
            'validus.test/*' => Http::response(['success' => true, 'products' => $this->validusProducts()]),
        ]);
    }

    protected function service(ProductWriter $writer): ProductSyncService
    {
        return new ProductSyncService(
            validus: new ValidusClient('https://validus.test/ecommerce_bridge', 'test-api-key'),
            shopify: $writer,
            grouping: new ProductCodeGroupingStrategy,
            locationId: 'gid://shopify/Location/1',
            pricesIncludeTax: false,
            trackNewVariants: false,
        );
    }

    public function test_it_groups_variants_into_one_product_and_creates_it_in_shopify(): void
    {
        $this->fakeValidusProductsEndpoint();

        $writer = Mockery::mock(ProductWriter::class);
        $writer->shouldReceive('upsertProduct')
            ->once()
            ->withArgs(function (array $product) {
                return $product['title'] === 'Demo Wine'
                    && $product['shopifyProductId'] === null
                    && count($product['variants']) === 2;
            })
            ->andReturn([
                'productId' => 'gid://shopify/Product/1',
                'variants' => [
                    ['id' => 'gid://shopify/ProductVariant/1', 'sku' => '56070025'],
                    ['id' => 'gid://shopify/ProductVariant/2', 'sku' => '56090125'],
                ],
            ]);
        $writer->shouldReceive('variantInventoryState')->andReturn([
            'gid://shopify/ProductVariant/1' => ['inventoryItemId' => 'gid://shopify/InventoryItem/1', 'tracked' => false],
            'gid://shopify/ProductVariant/2' => ['inventoryItemId' => 'gid://shopify/InventoryItem/2', 'tracked' => false],
        ]);
        $writer->shouldNotReceive('setInventoryQuantity'); // neither variant is tracked

        $result = $this->service($writer)->run();

        $this->assertSame(1, $result['groups']);
        $this->assertSame(2, $result['variants']);
        $this->assertSame(2, ProductMap::query()->count());
        $this->assertSame('gid://shopify/Product/1', ProductMap::query()->where('validus_id', '1001')->value('shopify_product_id'));
    }

    public function test_it_updates_the_existing_shopify_product_instead_of_creating_a_new_one(): void
    {
        $this->fakeValidusProductsEndpoint();

        ProductMap::query()->create([
            'validus_id' => '1001',
            'validus_code' => '56070025',
            'shopify_product_id' => 'gid://shopify/Product/1',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1',
        ]);

        $writer = Mockery::mock(ProductWriter::class);
        $writer->shouldReceive('upsertProduct')
            ->once()
            ->withArgs(fn (array $product) => $product['shopifyProductId'] === 'gid://shopify/Product/1')
            ->andReturn([
                'productId' => 'gid://shopify/Product/1',
                'variants' => [
                    ['id' => 'gid://shopify/ProductVariant/1', 'sku' => '56070025'],
                    ['id' => 'gid://shopify/ProductVariant/2', 'sku' => '56090125'],
                ],
            ]);
        $writer->shouldReceive('variantInventoryState')->andReturn([]);

        $this->service($writer)->run();

        $this->assertSame(2, ProductMap::query()->count());
    }

    public function test_it_pushes_stock_only_for_variants_already_tracked_in_shopify(): void
    {
        $this->fakeValidusProductsEndpoint();

        $writer = Mockery::mock(ProductWriter::class);
        $writer->shouldReceive('upsertProduct')->andReturn([
            'productId' => 'gid://shopify/Product/1',
            'variants' => [
                ['id' => 'gid://shopify/ProductVariant/1', 'sku' => '56070025'],
                ['id' => 'gid://shopify/ProductVariant/2', 'sku' => '56090125'],
            ],
        ]);
        $writer->shouldReceive('variantInventoryState')->andReturn([
            'gid://shopify/ProductVariant/1' => ['inventoryItemId' => 'gid://shopify/InventoryItem/1', 'tracked' => true],
            'gid://shopify/ProductVariant/2' => ['inventoryItemId' => 'gid://shopify/InventoryItem/2', 'tracked' => false],
        ]);
        $writer->shouldReceive('setInventoryQuantity')
            ->once()
            ->with('gid://shopify/InventoryItem/1', 'gid://shopify/Location/1', 100);

        $this->service($writer)->run();
    }

    public function test_dry_run_does_not_write_anything_to_shopify(): void
    {
        $this->fakeValidusProductsEndpoint();

        $writer = Mockery::mock(ProductWriter::class);
        $writer->shouldNotReceive('upsertProduct');
        $writer->shouldNotReceive('setInventoryQuantity');

        $result = $this->service($writer)->run(dryRun: true);

        $this->assertTrue($result['dryRun']);
        $this->assertSame(0, ProductMap::query()->count());
    }
}
