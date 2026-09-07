<?php

namespace Kreatif\ValidusShopifyBridge;

use Illuminate\Support\ServiceProvider;
use Kreatif\ValidusShopifyBridge\Clients\ValidusClient;
use Kreatif\ValidusShopifyBridge\Console\Commands\DiffValidusCatalog;
use Kreatif\ValidusShopifyBridge\Console\Commands\LinkExistingShopifyVariants;
use Kreatif\ValidusShopifyBridge\Console\Commands\SyncValidusProducts;
use Kreatif\ValidusShopifyBridge\Grouping\ProductCodeGroupingStrategy;
use Kreatif\ValidusShopifyBridge\Grouping\VariantGroupingStrategy;
use Kreatif\ValidusShopifyBridge\Services\OrderExportService;
use Kreatif\ValidusShopifyBridge\Services\ProductSyncService;
use Kreatif\ValidusShopifyBridge\Shopify\ProductWriter;
use Kreatif\ValidusShopifyBridge\Shopify\ShopifyGraphqlClient;

class ValidusShopifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/validus-shopify.php', 'validus-shopify');
        $this->registerLogChannel();

        $this->app->singleton(ValidusClient::class, fn () => new ValidusClient(
            baseUrl: rtrim((string) config('validus-shopify.validus.base_url'), '/'),
            apiKey: (string) config('validus-shopify.validus.api_key'),
            timeout: (int) config('validus-shopify.validus.timeout', 30),
        ));

        $this->app->singleton(VariantGroupingStrategy::class, fn () => new ProductCodeGroupingStrategy(
            productCodeLength: (int) config('validus-shopify.grouping.product_code_length', 2),
            yearCodeLength: (int) config('validus-shopify.grouping.year_code_length', 2),
            yearCenturyPrefix: (string) config('validus-shopify.grouping.year_century_prefix', '20'),
        ));

        $this->app->singleton(ShopifyGraphqlClient::class, fn () => new ShopifyGraphqlClient(
            storeDomain: (string) config('validus-shopify.shopify.store_url'),
            adminToken: (string) config('validus-shopify.shopify.admin_token'),
            apiVersion: (string) config('validus-shopify.shopify.api_version'),
        ));

        $this->app->singleton(ProductWriter::class, fn ($app) => new ProductWriter($app->make(ShopifyGraphqlClient::class)));

        $this->app->singleton(ProductSyncService::class, fn ($app) => new ProductSyncService(
            validus: $app->make(ValidusClient::class),
            shopify: $app->make(ProductWriter::class),
            grouping: $app->make(VariantGroupingStrategy::class),
            locationId: config('validus-shopify.shopify.location_id'),
            pricesIncludeTax: (bool) config('validus-shopify.shopify.prices_include_tax', false),
            trackNewVariants: (bool) config('validus-shopify.track_new_variants', false),
            vintageOptionName: (string) config('validus-shopify.shopify.option_names.vintage', 'Vintage'),
            formatOptionName: (string) config('validus-shopify.shopify.option_names.format', 'Format'),
        ));

        $this->app->singleton(OrderExportService::class, fn () => new OrderExportService(
            paymentCodeMap: (array) config('validus-shopify.payment_code_map', []),
        ));
    }

    /**
     * Registers a "validus-shopify" log channel so sync-products (and
     * future commands) have somewhere to record what was actually
     * imported/updated/skipped, without every consuming app needing to add
     * this to its own config/logging.php first. Uses the daily driver so
     * log rotation is handled by Laravel itself - no external logrotate
     * setup needed. A consuming app that defines its own "validus-shopify"
     * channel (e.g. to ship logs elsewhere) is left alone.
     */
    protected function registerLogChannel(): void
    {
        if (config('logging.channels.validus-shopify')) {
            return;
        }

        config([
            'logging.channels.validus-shopify' => [
                'driver' => 'daily',
                'path' => storage_path('logs/validus-shopify.log'),
                'level' => env('VALIDUS_SHOPIFY_LOG_LEVEL', 'info'),
                'days' => (int) env('VALIDUS_SHOPIFY_LOG_DAYS', 30),
            ],
        ]);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/validus-shopify.php' => config_path('validus-shopify.php'),
        ], 'validus-shopify-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncValidusProducts::class,
                DiffValidusCatalog::class,
                LinkExistingShopifyVariants::class,
            ]);
        }
    }
}
