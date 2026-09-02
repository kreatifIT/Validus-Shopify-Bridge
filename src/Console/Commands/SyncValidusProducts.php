<?php

namespace Kreatif\ValidusShopifyBridge\Console\Commands;

use Illuminate\Console\Command;
use Kreatif\ValidusShopifyBridge\Services\ProductSyncService;

class SyncValidusProducts extends Command
{
    protected $signature = 'validus-shopify:sync-products {--dry-run : Preview what would be created/updated in Shopify without writing anything}';

    protected $description = 'Import products, prices and stock from Validus into Shopify';

    public function handle(ProductSyncService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? 'Running in dry-run mode - nothing will be written to Shopify.' : 'Syncing products from Validus to Shopify...');

        $result = $service->run($dryRun);

        if ($dryRun) {
            $this->renderPreview($result['preview']);
        }

        $this->info("Done. {$result['groups']} Shopify product(s), {$result['variants']} variant(s) processed.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $preview
     */
    protected function renderPreview(array $preview): void
    {
        $rows = [];

        foreach ($preview as $group) {
            foreach ($group['variants'] as $variant) {
                $rows[] = [
                    strtoupper($group['action']),
                    $group['title'],
                    $variant['sku'],
                    $variant['vintage'],
                    $variant['format'],
                    $variant['price'],
                ];
            }
        }

        $this->table(['Action', 'Product', 'SKU', 'Vintage', 'Format', 'Price'], $rows);

        $createCount = collect($preview)->where('action', 'create')->count();
        $updateCount = collect($preview)->where('action', 'update')->count();

        $this->line("{$createCount} new Shopify product(s) would be created, {$updateCount} existing product(s) would be updated.");
    }
}
