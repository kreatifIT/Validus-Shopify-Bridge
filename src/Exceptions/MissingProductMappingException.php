<?php

namespace Kreatif\ValidusShopifyBridge\Exceptions;

use RuntimeException;

/**
 * Thrown when an order line item's Shopify variant has never been imported
 * from Validus, so there is no productId to report back. Order export fails
 * deliberately rather than sending an incomplete order.
 */
class MissingProductMappingException extends RuntimeException
{
    /**
     * $name/$sku come straight off the Shopify order line item (its "name"
     * and "sku" fields) - the raw Shopify variant id alone isn't something
     * anyone recognizes; the SKU is the Validus product code, so this is
     * the closest thing to a Validus product number this failure can carry
     * (there being no ProductMap entry is exactly why there isn't a real one).
     */
    public static function forVariant(string $shopifyVariantId, ?string $name, ?string $sku): self
    {
        $description = $name ?: 'unknown product';
        $skuPart = $sku ? ", SKU {$sku}" : '';

        return new self("No Validus product mapping found for \"{$description}\"{$skuPart} (Shopify variant [{$shopifyVariantId}]).");
    }
}
