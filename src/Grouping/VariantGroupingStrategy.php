<?php

namespace Kreatif\ValidusShopifyBridge\Grouping;

/**
 * Decides how flat Validus products (one row per vintage/format) are grouped
 * into a single Shopify product with multiple variants, and what vintage
 * year to use per variant. A common code.code layout is handled by
 * ProductCodeGroupingStrategy; a customer with a different Validus code
 * scheme can supply their own implementation and bind it in their own
 * service provider instead.
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
