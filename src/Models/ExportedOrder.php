<?php

namespace Kreatif\ValidusShopifyBridge\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $shopify_order_id
 * @property \Illuminate\Support\Carbon $exported_at
 */
class ExportedOrder extends Model
{
    protected $table = 'validus_shopify_exported_orders';

    protected $fillable = ['shopify_order_id', 'exported_at'];

    protected function casts(): array
    {
        return ['exported_at' => 'datetime'];
    }

    public static function alreadyExported(string $shopifyOrderId): bool
    {
        return static::query()->where('shopify_order_id', $shopifyOrderId)->exists();
    }
}
