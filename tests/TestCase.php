<?php

namespace Kreatif\ValidusShopifyBridge\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kreatif\ValidusShopifyBridge\ValidusShopifyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Kreatif\\ValidusShopifyBridge\\Tests\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app): array
    {
        return [ValidusShopifyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('validus-shopify.validus.base_url', 'https://validus.test/ecommerce_bridge');
        $app['config']->set('validus-shopify.validus.api_key', 'test-api-key');
        $app['config']->set('validus-shopify.shopify.store_url', 'test-shop.myshopify.com');
        $app['config']->set('validus-shopify.shopify.admin_token', 'test-admin-token');
        $app['config']->set('validus-shopify.shopify.webhook_secret', 'test-webhook-secret');
        $app['config']->set('validus-shopify.shopify.location_id', 'gid://shopify/Location/1');
        $app['config']->set('validus-shopify.payment_code_map', ['shopify_payments' => 'CC']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
