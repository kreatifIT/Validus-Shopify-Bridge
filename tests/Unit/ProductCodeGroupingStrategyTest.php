<?php

namespace Kreatif\ValidusShopifyBridge\Tests\Unit;

use Kreatif\ValidusShopifyBridge\Grouping\ProductCodeGroupingStrategy;
use Kreatif\ValidusShopifyBridge\Tests\TestCase;

class ProductCodeGroupingStrategyTest extends TestCase
{
    protected ProductCodeGroupingStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->strategy = new ProductCodeGroupingStrategy;
    }

    public function test_group_key_uses_first_two_digits_of_code(): void
    {
        $sauvignon = ['code' => ['code' => '56070025']];
        $appius = ['code' => ['code' => '99070121']];

        $this->assertSame('56', $this->strategy->groupKey($sauvignon));
        $this->assertSame('99', $this->strategy->groupKey($appius));
    }

    public function test_different_formats_of_the_same_product_share_a_group_key(): void
    {
        $variants = [
            ['code' => ['code' => '56030025']],
            ['code' => ['code' => '56070025']],
            ['code' => ['code' => '56090125']],
            ['code' => ['code' => '56100125']],
        ];

        $keys = array_map(fn (array $v) => $this->strategy->groupKey($v), $variants);

        $this->assertSame(['56', '56', '56', '56'], $keys);
    }

    public function test_vintage_year_prefers_the_structured_code_year_field(): void
    {
        $product = ['code' => ['code' => '99070121', 'year' => 2021]];

        $this->assertSame(2021, $this->strategy->vintageYear($product));
    }

    public function test_vintage_year_falls_back_to_parsing_the_code_when_year_field_missing(): void
    {
        $product = ['code' => ['code' => '56070025']];

        $this->assertSame(2025, $this->strategy->vintageYear($product));
    }

    public function test_vintage_year_is_null_when_code_is_too_short(): void
    {
        $product = ['code' => ['code' => '5']];

        $this->assertNull($this->strategy->vintageYear($product));
    }
}
