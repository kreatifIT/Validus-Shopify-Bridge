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

        $this->renderDeactivation($result['deactivation'], $dryRun);
        $this->renderFailures($result['failures']);

        $this->info("Done. {$result['groups']} Shopify product(s), {$result['variants']} variant(s) processed.");

        return empty($result['failures']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<int, array{groupKey: string, title: string, message: string}>  $failures
     */
    protected function renderFailures(array $failures): void
    {
        if (empty($failures)) {
            return;
        }

        $this->newLine();
        $this->error(count($failures).' product group(s) failed and were skipped - the rest of the sync still ran. A ProductSyncGroupFailed event was fired for each one:');

        foreach ($failures as $failure) {
            $this->line("  - {$failure['title']} ({$failure['groupKey']}): {$failure['message']}");
        }
    }

    /**
     * @param  array{skipped: ?string, variants: int, products: int}  $deactivation
     */
    protected function renderDeactivation(array $deactivation, bool $dryRun): void
    {
        if ($deactivation['skipped'] === 'safety-threshold') {
            $this->warn("Skipped deactivating {$deactivation['variants']} variant(s) - removed share exceeded validus-shopify.deactivation.max_removed_ratio. This usually means Validus returned an incomplete catalog rather than {$deactivation['variants']} real discontinuations; check before running validus-shopify:diff manually.");

            return;
        }

        if ($deactivation['skipped'] || $deactivation['variants'] === 0) {
            return;
        }

        $verb = $dryRun ? 'would deactivate' : 'deactivated';
        $this->line("{$verb} {$deactivation['variants']} variant(s) no longer in Validus".($deactivation['products'] > 0 ? ", archived {$deactivation['products']} product(s) with no remaining active variant" : '').'.');
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
