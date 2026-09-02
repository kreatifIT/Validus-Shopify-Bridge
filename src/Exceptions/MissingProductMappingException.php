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
    public static function forVariant(string $shopifyVariantId): self
    {
        return new self("No Validus product mapping found for Shopify variant [{$shopifyVariantId}].");
    }
}
