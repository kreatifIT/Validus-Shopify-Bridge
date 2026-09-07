<?php

namespace Kreatif\ValidusShopifyBridge\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Kreatif\ValidusShopifyBridge\Clients\ValidusClient;
use Kreatif\ValidusShopifyBridge\Grouping\VariantGroupingStrategy;
use Kreatif\ValidusShopifyBridge\Models\ProductMap;
use Kreatif\ValidusShopifyBridge\Shopify\ProductWriter;

/**
 * Read-only preview of what a real sync would do, going further than
 * `sync-products --dry-run`: it looks up every Validus SKU in Shopify
 * directly (not just via our own validus_shopify_product_map), so it also
 * catches:
 *
 * - a SKU that already exists in Shopify but has no map row yet, which
 *   `sync-products` would otherwise turn into a *duplicate* product (run
 *   `validus-shopify:link-existing` to fix this before syncing for real)
 * - a price difference between what's currently in Shopify and what
 *   Validus would write on the next sync
 * - a previously-linked product that Validus no longer returns at all
 *   (removed/discontinued in the ERP), which sync-products has no way to
 *   detect since it only ever adds/updates, never removes, mapped products
 *
 * That last check is necessarily scoped to already-linked products (the
 * validus_shopify_product_map table) - it can't tell a Shopify product this
 * package never touched apart from one Validus stopped listing.
 */
class DiffValidusCatalog extends Command
{
    protected $signature = 'validus-shopify:diff {--all : Also list variants whose price is unchanged}';

    protected $description = 'Compare the Validus catalog against the current Shopify state without writing anything';

    public function handle(ValidusClient $validus, VariantGroupingStrategy $grouping, ProductWriter $shopify): int
    {
        $pricesIncludeTax = (bool) config('validus-shopify.shopify.prices_include_tax', false);

        $rows = [];

        foreach ($validus->getProducts() as $validusProduct) {
            $sku = $validusProduct['code']['code'] ?? (string) $validusProduct['id'];

            $rows[$sku] = [
                'validus_id' => (string) $validusProduct['id'],
                'title' => $validusProduct['name'] ?? $grouping->groupKey($validusProduct),
                'sku' => $sku,
                'vintage' => (string) ($grouping->vintageYear($validusProduct) ?? ''),
                'format' => $this->formatLabel($validusProduct),
                'validus_price' => $this->price($validusProduct, $pricesIncludeTax),
            ];
        }

        $mapped = ProductMap::query()
            ->whereIn('validus_id', array_column($rows, 'validus_id'))
            ->pluck('validus_id')
            ->all();

        $shopifyMatches = $shopify->variantsBySku(array_keys($rows));

        $conflicts = [];
        $new = [];
        $changed = [];
        $unchanged = [];

        foreach ($rows as $row) {
            $isMapped = in_array($row['validus_id'], $mapped, true);
            $match = $shopifyMatches[$row['sku']] ?? null;

            if (! $isMapped) {
                if ($match) {
                    $conflicts[] = [
                        ...$row,
                        'shopify_price' => $match['price'],
                        'shopify_product' => $match['productTitle'],
                        'shopify_product_id' => $match['productId'],
                    ];
                } else {
                    $new[] = [...$row, 'shopify_price' => null];
                }

                continue;
            }

            $shopifyPrice = $match['price'] ?? null;
            $entry = [...$row, 'shopify_price' => $shopifyPrice];

            if ($shopifyPrice === null || bccomp($shopifyPrice, $row['validus_price'], 2) !== 0) {
                $changed[] = $entry;
            } else {
                $unchanged[] = $entry;
            }
        }

        $removed = $this->findRemovedFromValidus($shopify, $rows);

        if (! empty($conflicts)) {
            $this->newLine();
            $this->error('FOUND IN SHOPIFY BUT NOT LINKED - a real sync would create duplicate products for these. Run validus-shopify:link-existing first.');
            $this->printGroup(null, $conflicts, withDiff: true, withShopifyProduct: true);
        }

        if (! empty($removed)) {
            $this->newLine();
            $this->error('IN SHOPIFY BUT MISSING FROM VALIDUS - previously linked, but Validus no longer lists this product. sync-products never removes a mapping on its own, check whether it was discontinued on purpose.');
            $this->printGroup(null, $removed, withShopifyProduct: true);
        }

        $this->printGroup('NEW (no SKU match in Shopify)', $new);
        $this->printGroup('PRICE CHANGED (already linked)', $changed, withDiff: true);

        if ($this->option('all')) {
            $this->printGroup('UNCHANGED (already linked)', $unchanged);
        }

        $this->newLine();
        $this->info(sprintf(
            'Summary: %d found but unlinked, %d truly new, %d linked with a price change, %d linked and unchanged, %d linked but missing from Validus (%d Validus variant(s) total).',
            count($conflicts),
            count($new),
            count($changed),
            count($unchanged),
            count($removed),
            count($rows),
        ));

        return self::SUCCESS;
    }

    /**
     * Already-linked products (validus_shopify_product_map) whose validus_id
     * Validus no longer returns at all - the ERP dropped the product but
     * nothing here ever un-links or deactivates it automatically.
     *
     * @param  array<string, array<string, mixed>>  $currentRows  Keyed by SKU, as built in handle().
     * @return array<int, array<string, mixed>>
     */
    protected function findRemovedFromValidus(ProductWriter $shopify, array $currentRows): array
    {
        $staleMaps = ProductMap::query()
            ->whereNotIn('validus_id', array_column($currentRows, 'validus_id'))
            ->get();

        if ($staleMaps->isEmpty()) {
            return [];
        }

        $matches = $shopify->variantsBySku($staleMaps->pluck('validus_code')->all());

        return $staleMaps->map(function (ProductMap $map) use ($matches) {
            $match = $matches[$map->validus_code] ?? null;

            return [
                'title' => $match['productTitle'] ?? '(not found in Shopify either - product may have been deleted there too)',
                'sku' => $map->validus_code,
                'vintage' => '',
                'format' => '',
                'validus_price' => '-',
                'shopify_price' => $match['price'] ?? null,
                'shopify_product' => $match['productTitle'] ?? '-',
                'shopify_product_id' => $match['productId'] ?? $map->shopify_product_id,
            ];
        })->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function printGroup(?string $heading, array $rows, bool $withDiff = false, bool $withShopifyProduct = false): void
    {
        if ($heading !== null) {
            $this->newLine();
            $this->line("<fg=yellow;options=bold>{$heading}</> (".count($rows).')');
        }

        if (empty($rows)) {
            return;
        }

        $headers = ['Title', 'SKU', 'Vintage', 'Format', 'Shopify price', 'Validus price'];

        if ($withDiff) {
            $headers[] = 'Diff';
        }

        if ($withShopifyProduct) {
            $headers[] = 'Existing Shopify product';
        }

        $this->table($headers, array_map(function (array $row) use ($withDiff, $withShopifyProduct) {
            $line = [
                $row['title'],
                $row['sku'],
                $row['vintage'],
                $row['format'],
                $row['shopify_price'] ?? '-',
                $row['validus_price'],
            ];

            if ($withDiff) {
                $line[] = $row['shopify_price'] === null ? '-' : bcsub($row['validus_price'], $row['shopify_price'], 2);
            }

            if ($withShopifyProduct) {
                $line[] = "{$row['shopify_product']} ({$row['shopify_product_id']})";
            }

            return $line;
        }, $rows));
    }

    /**
     * @param  array<string, mixed>  $validusProduct
     */
    protected function price(array $validusProduct, bool $pricesIncludeTax): string
    {
        $net = Arr::get($validusProduct, 'price.discountedPrice') ?? Arr::get($validusProduct, 'price.fullPrice', 0);

        if ($pricesIncludeTax) {
            $rate = (float) Arr::get($validusProduct, 'tax.rate', 0);
            $net = $net * (1 + $rate / 100);
        }

        return number_format((float) $net, 2, '.', '');
    }

    /**
     * Mirrors ProductSyncService::formatLabel() - kept in sync manually
     * since the service doesn't expose it publicly.
     *
     * @param  array<string, mixed>  $validusProduct
     */
    protected function formatLabel(array $validusProduct): string
    {
        $capacity = Arr::get($validusProduct, 'code.bottleCapacity');

        if ($capacity === null) {
            return 'unknown';
        }

        $centiliters = $capacity * 100;

        $formatted = floor($centiliters) == $centiliters
            ? (string) (int) $centiliters
            : number_format($centiliters, 1, '.', '');

        return "{$formatted}cl";
    }
}
