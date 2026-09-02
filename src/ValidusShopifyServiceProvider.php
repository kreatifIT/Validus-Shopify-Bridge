<?php

namespace Kreatif\ValidusShopifyBridge;

use Illuminate\Support\ServiceProvider;
use Kreatif\ValidusShopifyBridge\Clients\ValidusClient;
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
        ));

        $this->app->singleton(OrderExportService::class, fn () => new OrderExportService(
            paymentCodeMap: (array) config('validus-shopify.payment_code_map', []),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/validus-shopify.php' => config_path('validus-shopify.php'),
        ], 'validus-shopify-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');

        if ($this->app->runningInConsole()) {
            $this->commands([SyncValidusProducts::class]);
        }
    }
}
