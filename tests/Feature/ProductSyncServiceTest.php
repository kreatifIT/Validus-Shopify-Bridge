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

    public function test_dry_run_previews_what_would_be_created_or_updated(): void
    {
        $this->fakeValidusProductsEndpoint();

        $writer = Mockery::mock(ProductWriter::class);
        $writer->shouldNotReceive('upsertProduct');

        $result = $this->service($writer)->run(dryRun: true);

        $this->assertCount(1, $result['preview']);
        $this->assertSame('create', $result['preview'][0]['action']);
        $this->assertSame('Demo Wine', $result['preview'][0]['title']);
        $this->assertCount(2, $result['preview'][0]['variants']);
        $this->assertSame('56070025', $result['preview'][0]['variants'][0]['sku']);
        $this->assertSame('2025', $result['preview'][0]['variants'][0]['vintage']);
    }

    public function test_dry_run_reports_update_when_the_product_is_already_mapped(): void
    {
        $this->fakeValidusProductsEndpoint();

        ProductMap::query()->create([
            'validus_id' => '1001',
            'validus_code' => '56070025',
            'shopify_product_id' => 'gid://shopify/Product/1',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1',
        ]);

        $writer = Mockery::mock(ProductWriter::class);
        $writer->shouldNotReceive('upsertProduct');

        $result = $this->service($writer)->run(dryRun: true);

        $this->assertSame('update', $result['preview'][0]['action']);
        $this->assertSame('gid://shopify/Product/1', $result['preview'][0]['shopifyProductId']);
    }

    protected function baseVariantMapRows(): void
    {
        ProductMap::query()->create([
            'validus_id' => '1001',
            'validus_code' => '56070025',
            'shopify_product_id' => 'gid://shopify/Product/1',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1',
        ]);
        ProductMap::query()->create([
            'validus_id' => '1002',
            'validus_code' => '56090125',
            'shopify_product_id' => 'gid://shopify/Product/1',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/2',
        ]);
    }

    protected function stubNormalGroupUpsert(\Mockery\MockInterface $writer): void
    {
        $writer->shouldReceive('upsertProduct')->andReturn([
            'productId' => 'gid://shopify/Product/1',
            'variants' => [
                ['id' => 'gid://shopify/ProductVariant/1', 'sku' => '56070025'],
                ['id' => 'gid://shopify/ProductVariant/2', 'sku' => '56090125'],
            ],
        ]);
    }

    public function test_it_deactivates_and_archives_a_product_whose_only_variant_is_gone_from_validus(): void
    {
        $this->fakeValidusProductsEndpoint();
        $this->baseVariantMapRows();

        // Discontinued: not in the Validus fixture, was the only variant mapped to this Shopify product.
        ProductMap::query()->create([
            'validus_id' => '9999',
            'validus_code' => '77777777',
            'shopify_product_id' => 'gid://shopify/Product/9',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/9',
        ]);

        $writer = Mockery::mock(ProductWriter::class);
        $this->stubNormalGroupUpsert($writer);
        $writer->shouldReceive('variantInventoryState')->andReturn([
            'gid://shopify/ProductVariant/9' => ['inventoryItemId' => 'gid://shopify/InventoryItem/9', 'tracked' => false],
        ]);
        $writer->shouldReceive('setInventoryTracked')->once()->with('gid://shopify/InventoryItem/9');
        $writer->shouldReceive('setInventoryQuantity')->once()->with('gid://shopify/InventoryItem/9', 'gid://shopify/Location/1', 0);
        $writer->shouldReceive('setVariantInventoryPolicy')->once()->with('gid://shopify/Product/9', 'gid://shopify/ProductVariant/9', 'DENY');
        $writer->shouldReceive('setProductStatus')->once()->with('gid://shopify/Product/9', 'ARCHIVED');

        $result = $this->service($writer)->run();

        $this->assertSame(1, $result['deactivation']['variants']);
        $this->assertSame(1, $result['deactivation']['products']);
    }

    public function test_it_deactivates_a_variant_without_archiving_the_product_when_siblings_remain(): void
    {
        $this->fakeValidusProductsEndpoint();
        $this->baseVariantMapRows();

        // A third variant that used to share the SAME Shopify product with 1001/1002, but Validus dropped it.
        ProductMap::query()->create([
            'validus_id' => '1003',
            'validus_code' => '56100123',
            'shopify_product_id' => 'gid://shopify/Product/1',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/3',
        ]);

        $writer = Mockery::mock(ProductWriter::class);
        $this->stubNormalGroupUpsert($writer);
        $writer->shouldReceive('variantInventoryState')->andReturn([
            'gid://shopify/ProductVariant/3' => ['inventoryItemId' => 'gid://shopify/InventoryItem/3', 'tracked' => true],
        ]);
        $writer->shouldReceive('setInventoryQuantity')->once()->with('gid://shopify/InventoryItem/3', 'gid://shopify/Location/1', 0);
        $writer->shouldReceive('setVariantInventoryPolicy')->once()->with('gid://shopify/Product/1', 'gid://shopify/ProductVariant/3', 'DENY');
        $writer->shouldNotReceive('setInventoryTracked'); // already tracked
        $writer->shouldNotReceive('setProductStatus'); // siblings 1001/1002 are still current

        $result = $this->service($writer)->run();

        $this->assertSame(1, $result['deactivation']['variants']);
        $this->assertSame(0, $result['deactivation']['products']);
    }

    public function test_deactivation_dry_run_does_not_call_shopify(): void
    {
        $this->fakeValidusProductsEndpoint();
        $this->baseVariantMapRows(); // keeps the removed share under the safety threshold

        ProductMap::query()->create([
            'validus_id' => '9999',
            'validus_code' => '77777777',
            'shopify_product_id' => 'gid://shopify/Product/9',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/9',
        ]);

        $writer = Mockery::mock(ProductWriter::class);
        $writer->shouldNotReceive('upsertProduct');
        $writer->shouldNotReceive('setInventoryTracked');
        $writer->shouldNotReceive('setInventoryQuantity');
        $writer->shouldNotReceive('setVariantInventoryPolicy');
        $writer->shouldNotReceive('setProductStatus');

        $result = $this->service($writer)->run(dryRun: true);

        $this->assertSame(1, $result['deactivation']['variants']);
        $this->assertSame(1, $result['deactivation']['products']);
    }

    public function test_deactivation_is_skipped_when_removed_share_exceeds_the_safety_threshold(): void
    {
        $this->fakeValidusProductsEndpoint();

        // 3 mapped products, none of them in the 2-product Validus fixture -> 100% removed, above the 50% default.
        foreach (['9001', '9002', '9003'] as $id) {
            ProductMap::query()->create([
                'validus_id' => $id,
                'validus_code' => $id,
                'shopify_product_id' => "gid://shopify/Product/{$id}",
                'shopify_variant_id' => "gid://shopify/ProductVariant/{$id}",
            ]);
        }

        $writer = Mockery::mock(ProductWriter::class);
        $this->stubNormalGroupUpsert($writer);
        $writer->shouldReceive('variantInventoryState')->andReturn([]);
        $writer->shouldNotReceive('setInventoryTracked');
        $writer->shouldNotReceive('setVariantInventoryPolicy');
        $writer->shouldNotReceive('setProductStatus');

        $result = $this->service($writer)->run();

        $this->assertSame('safety-threshold', $result['deactivation']['skipped']);
        $this->assertSame(3, $result['deactivation']['variants']);
        $this->assertSame(0, $result['deactivation']['products']);
    }

    public function test_deactivation_is_skipped_when_disabled_via_config(): void
    {
        config(['validus-shopify.deactivation.enabled' => false]);

        $this->fakeValidusProductsEndpoint();

        ProductMap::query()->create([
            'validus_id' => '9999',
            'validus_code' => '77777777',
            'shopify_product_id' => 'gid://shopify/Product/9',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/9',
        ]);

        $writer = Mockery::mock(ProductWriter::class);
        $this->stubNormalGroupUpsert($writer);
        $writer->shouldReceive('variantInventoryState')->andReturn([]);
        $writer->shouldNotReceive('setProductStatus');

        $result = $this->service($writer)->run();

        $this->assertSame('disabled', $result['deactivation']['skipped']);
    }

    public function test_deactivation_is_skipped_without_a_location_id(): void
    {
        $this->fakeValidusProductsEndpoint();

        ProductMap::query()->create([
            'validus_id' => '9999',
            'validus_code' => '77777777',
            'shopify_product_id' => 'gid://shopify/Product/9',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/9',
        ]);

        $writer = Mockery::mock(ProductWriter::class);
        $this->stubNormalGroupUpsert($writer);
        $writer->shouldReceive('variantInventoryState')->andReturn([]);
        $writer->shouldNotReceive('setProductStatus');

        $service = new ProductSyncService(
            validus: new ValidusClient('https://validus.test/ecommerce_bridge', 'test-api-key'),
            shopify: $writer,
            grouping: new ProductCodeGroupingStrategy,
            locationId: null,
            pricesIncludeTax: false,
            trackNewVariants: false,
        );

        $result = $service->run();

        $this->assertSame('disabled', $result['deactivation']['skipped']);
    }
}
