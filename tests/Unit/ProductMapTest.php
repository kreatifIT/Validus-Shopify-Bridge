<?php

namespace Kreatif\ValidusShopifyBridge\Tests\Unit;

use Kreatif\ValidusShopifyBridge\Models\ProductMap;
use Kreatif\ValidusShopifyBridge\Tests\TestCase;

class ProductMapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // What storeMapping() actually persists: the full gid://... string
        // as returned by the GraphQL productSet mutation.
        ProductMap::query()->create([
            'validus_id' => '101512',
            'validus_code' => '99070121',
            'shopify_product_id' => 'gid://shopify/Product/1',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/424242',
        ]);
    }

    public function test_it_finds_a_mapping_by_the_bare_numeric_variant_id(): void
    {
        // What Shopify's REST "orders/paid" webhook gives as
        // line_items[].variant_id - never a gid://... string.
        $map = ProductMap::findByShopifyVariantId('424242');

        $this->assertNotNull($map);
        $this->assertSame('99070121', $map->validus_code);
    }

    public function test_it_finds_a_mapping_by_the_full_gid(): void
    {
        $map = ProductMap::findByShopifyVariantId('gid://shopify/ProductVariant/424242');

        $this->assertNotNull($map);
        $this->assertSame('99070121', $map->validus_code);
    }

    public function test_it_returns_null_for_a_variant_id_that_was_never_mapped(): void
    {
        $this->assertNull(ProductMap::findByShopifyVariantId('999999'));
    }
}
