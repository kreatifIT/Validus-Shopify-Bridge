<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        // The Shopify order ID alone can't be looked up by hand - the order
        // number ("#1013") is what Shopify Admin and Validus show.
        Schema::table('validus_shopify_exported_orders', function (Blueprint $table) {
            $table->string('shopify_order_number')->nullable()->index()->after('shopify_order_id');
        });

        $this->fillFromStoredWebhooks();
    }

    public function down(): void
    {
        Schema::table('validus_shopify_exported_orders', function (Blueprint $table) {
            $table->dropIndex(['shopify_order_number']);
            $table->dropColumn('shopify_order_number');
        });
    }

    /**
     * Orders exported before this migration get their number from the stored
     * "orders/paid" webhooks (order_export.webhook_log_*), as far as those are
     * still kept.
     */
    private function fillFromStoredWebhooks(): void
    {
        $disk = config('validus-shopify.order_export.webhook_log_disk');

        if (! $disk) {
            return;
        }

        $storage = Storage::disk($disk);

        foreach ($storage->files(config('validus-shopify.order_export.webhook_log_directory', 'validus-order-webhooks')) as $path) {
            $order = json_decode((string) $storage->get($path), true);

            if (! is_array($order) || ! isset($order['id'], $order['name'])) {
                continue;
            }

            DB::table('validus_shopify_exported_orders')
                ->where('shopify_order_id', (string) $order['id'])
                ->whereNull('shopify_order_number')
                ->update(['shopify_order_number' => (string) $order['name']]);
        }
    }
};
