<?php

namespace Kreatif\ValidusShopifyBridge\Console\Commands;

use Illuminate\Console\Command;
use Kreatif\ValidusShopifyBridge\Clients\ValidusClient;
use Kreatif\ValidusShopifyBridge\Grouping\VariantGroupingStrategy;
use Kreatif\ValidusShopifyBridge\Models\ProductMap;
use Kreatif\ValidusShopifyBridge\Shopify\ProductWriter;

/**
 * One-time (per store) backfill for adopting this package into a Shopify
 * catalog that already has products in it from before Validus was wired up
 * (manually created, or imported some other way). ProductSyncService only
 * ever consults its own validus_shopify_product_map table to decide
 * create-vs-update, so without this, the first real sync would create a
 * duplicate Shopify product for every Validus SKU that happens to already
 * exist under the same SKU. This matches by SKU instead and links them.
 */
class LinkExistingShopifyVariants extends Command
{
    protected $signature = 'validus-shopify:link-existing {--dry-run : List matches without writing anything}';

    protected $description = 'Link Validus products to their already-existing Shopify variant by matching SKU, so the next real sync updates instead of duplicating them';

    public function handle(ValidusClient $validus, VariantGroupingStrategy $grouping, ProductWriter $shopify): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $products = collect($validus->getProducts())->keyBy(
            fn (array $product) => $product['code']['code'] ?? (string) $product['id']
        );

        $alreadyMapped = ProductMap::query()
            ->whereIn('validus_id', $products->map(fn (array $product) => (string) $product['id'])->all())
            ->pluck('validus_id')
            ->all();

        $unmapped = $products->reject(
            fn (array $product) => in_array((string) $product['id'], $alreadyMapped, true)
        );

        if ($unmapped->isEmpty()) {
            $this->info('Nothing to link - every Validus product already has a mapping.');

            return self::SUCCESS;
        }

        $matches = $shopify->variantsBySku($unmapped->keys()->all());
        $linked = 0;

        foreach ($unmapped as $sku => $validusProduct) {
            $match = $matches[$sku] ?? null;

            if (! $match) {
                continue;
            }

            $this->line(sprintf(
                '%s SKU %s -> %s (%s)',
                $dryRun ? '[DRY-RUN]' : '[LINKED] ',
                $sku,
                $match['productTitle'],
                $match['productId'],
            ));

            if (! $dryRun) {
                ProductMap::query()->updateOrCreate(
                    ['validus_id' => (string) $validusProduct['id']],
                    [
                        'validus_code' => $sku,
                        'shopify_product_id' => $match['productId'],
                        'shopify_variant_id' => $match['id'],
                    ],
                );
            }

            $linked++;
        }

        $this->newLine();
        $this->info(sprintf(
            '%d of %d unmapped Validus product(s) matched an existing Shopify variant by SKU%s.',
            $linked,
            $unmapped->count(),
            $dryRun ? ' (dry-run, nothing written)' : '',
        ));

        return self::SUCCESS;
    }
}
