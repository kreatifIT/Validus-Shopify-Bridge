<?php

namespace Kreatif\ValidusShopifyBridge\Grouping;

/**
 * Decides how flat Validus products (one row per vintage/format) are grouped
 * into a single Shopify product with multiple variants, and what vintage
 * year to use per variant. Kellerei St. Michael's code format is handled by
 * ProductCodeGroupingStrategy; a future customer with a different Validus
 * code scheme can supply their own implementation via config.
 */
interface VariantGroupingStrategy
{
    /**
     * @param  array<string, mixed>  $validusProduct  One entry from ValidusClient::getProducts().
     * @return string Stable key shared by every Validus product that belongs to the same Shopify product.
     */
    public function groupKey(array $validusProduct): string;

    /**
     * @param  array<string, mixed>  $validusProduct
     */
    public function vintageYear(array $validusProduct): ?int;
}
