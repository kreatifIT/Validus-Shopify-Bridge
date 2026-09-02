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

    public static function findByShopifyVariantId(string $shopifyVariantId): ?self
    {
        return static::query()->where('shopify_variant_id', $shopifyVariantId)->first();
    }
}
