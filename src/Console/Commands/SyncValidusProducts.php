<?php

namespace Kreatif\ValidusShopifyBridge\Console\Commands;

use Illuminate\Console\Command;
use Kreatif\ValidusShopifyBridge\Services\ProductSyncService;

class SyncValidusProducts extends Command
{
    protected $signature = 'validus-shopify:sync-products {--dry-run : Fetch and group Validus products without writing anything to Shopify}';

    protected $description = 'Import products, prices and stock from Validus into Shopify';

    public function handle(ProductSyncService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? 'Running in dry-run mode - nothing will be written to Shopify.' : 'Syncing products from Validus to Shopify...');

        $result = $service->run($dryRun);

        $this->info("Done. {$result['groups']} Shopify product(s), {$result['variants']} variant(s) processed.");

        return self::SUCCESS;
    }
}
