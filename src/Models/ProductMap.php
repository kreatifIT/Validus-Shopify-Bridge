<?php

namespace Kreatif\ValidusShopifyBridge\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $validus_id
 * @property string $validus_code
 * @property string|null $shopify_product_id
 * @property string|null $shopify_variant_id
 */
class ProductMap extends Model
{
    protected $table = 'validus_shopify_product_map';

    protected $fillable = [
        'validus_id',
        'validus_code',
        'shopify_product_id',
        'shopify_variant_id',
    ];

    /**
     * $shopifyVariantId can be either the bare numeric id - what Shopify's
     * REST "orders/paid" webhook gives as line_items[].variant_id - or the
     * full gid://... string, which is what's actually stored here (it comes
     * back that way from the GraphQL productSet mutation when the row is
     * created). Without normalizing, an order webhook lookup would silently
     * miss a real, correctly-synced mapping every time.
     */
    public static function findByShopifyVariantId(string $shopifyVariantId): ?self
    {
        return static::query()->where('shopify_variant_id', static::toVariantGid($shopifyVariantId))->first();
    }

    protected static function toVariantGid(string $shopifyVariantId): string
    {
        return str_starts_with($shopifyVariantId, 'gid://') ? $shopifyVariantId : "gid://shopify/ProductVariant/{$shopifyVariantId}";
    }
}
