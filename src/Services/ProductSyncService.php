<?php

namespace Kreatif\ValidusShopifyBridge\Services;

use Illuminate\Support\Arr;
use Kreatif\ValidusShopifyBridge\Clients\ValidusClient;
use Kreatif\ValidusShopifyBridge\Grouping\VariantGroupingStrategy;
use Kreatif\ValidusShopifyBridge\Models\ProductMap;
use Kreatif\ValidusShopifyBridge\Shopify\ProductWriter;

class ProductSyncService
{
    public function __construct(
        protected ValidusClient $validus,
        protected ProductWriter $shopify,
        protected VariantGroupingStrategy $grouping,
        protected ?string $locationId,
        protected bool $pricesIncludeTax,
        protected bool $trackNewVariants,
        protected string $vintageOptionName = 'Vintage',
        protected string $formatOptionName = 'Format',
    ) {}

    /**
     * @return array{groups: int, variants: int, dryRun: bool, preview: array<int, array<string, mixed>>, deactivation: array{skipped: ?string, variants: int, products: int}}
     */
    public function run(bool $dryRun = false): array
    {
        $validusProducts = $this->validus->getProducts();

        $groups = collect($validusProducts)
            ->groupBy(fn (array $product) => $this->grouping->groupKey($product));

        $variantCount = 0;
        $preview = [];

        foreach ($groups as $groupKey => $groupProducts) {
            $groupPreview = $this->syncGroup((string) $groupKey, $groupProducts->all(), $dryRun);
            $variantCount += $groupProducts->count();

            if ($dryRun) {
                $preview[] = $groupPreview;
            }
        }

        $currentValidusIds = collect($validusProducts)->map(fn (array $product) => (string) $product['id'])->all();
        $deactivation = $this->deactivateRemoved($currentValidusIds, $dryRun);

        return [
            'groups' => $groups->count(),
            'variants' => $variantCount,
            'dryRun' => $dryRun,
            'preview' => $preview,
            'deactivation' => $deactivation,
        ];
    }

    /**
     * Deactivates every already-linked variant whose Validus product has
     * disappeared from the catalog entirely, and archives the Shopify
     * product too once every variant mapped to it is gone - see the
     * "deactivation" config block for the guardrails (can be turned off
     * entirely, and skips itself if the removed share looks too large to be
     * a real batch of discontinuations rather than a bad Validus response).
     *
     * @param  array<int, string>  $currentValidusIds
     * @return array{skipped: ?string, variants: int, products: int}
     */
    protected function deactivateRemoved(array $currentValidusIds, bool $dryRun): array
    {
        if (! config('validus-shopify.deactivation.enabled', true) || ! $this->locationId) {
            return ['skipped' => 'disabled', 'variants' => 0, 'products' => 0];
        }

        $staleMaps = ProductMap::query()->whereNotIn('validus_id', $currentValidusIds)->get();

        if ($staleMaps->isEmpty()) {
            return ['skipped' => null, 'variants' => 0, 'products' => 0];
        }

        $totalMapped = ProductMap::query()->count();
        $removedRatio = $totalMapped > 0 ? $staleMaps->count() / $totalMapped : 0;
        $maxRatio = (float) config('validus-shopify.deactivation.max_removed_ratio', 0.5);

        if (count($currentValidusIds) === 0 || $removedRatio > $maxRatio) {
            return ['skipped' => 'safety-threshold', 'variants' => $staleMaps->count(), 'products' => 0];
        }

        $deactivatedVariants = 0;
        $archivedProducts = 0;

        foreach ($staleMaps->groupBy('shopify_product_id') as $shopifyProductId => $staleForProduct) {
            foreach ($staleForProduct as $map) {
                if (! $dryRun) {
                    $this->deactivateVariant($map);
                }

                $deactivatedVariants++;
            }

            $totalForProduct = ProductMap::query()->where('shopify_product_id', $shopifyProductId)->count();
            $allVariantsRemoved = $totalForProduct > 0 && $staleForProduct->count() === $totalForProduct;

            if ($allVariantsRemoved) {
                if (! $dryRun) {
                    $this->shopify->setProductStatus($shopifyProductId, 'ARCHIVED');
                }

                $archivedProducts++;
            }
        }

        return ['skipped' => null, 'variants' => $deactivatedVariants, 'products' => $archivedProducts];
    }

    protected function deactivateVariant(ProductMap $map): void
    {
        $state = $this->shopify->variantInventoryState([$map->shopify_variant_id])[$map->shopify_variant_id] ?? null;
        $inventoryItemId = $state['inventoryItemId'] ?? null;

        if (! $inventoryItemId) {
            return;
        }

        if (! ($state['tracked'] ?? false)) {
            $this->shopify->setInventoryTracked($inventoryItemId);
        }

        $this->shopify->setInventoryQuantity($inventoryItemId, $this->locationId, 0);
        $this->shopify->setVariantInventoryPolicy($map->shopify_product_id, $map->shopify_variant_id, 'DENY');
    }

    /**
     * Builds the product/variant payload and looks up whether it would
     * create a new Shopify product or update an existing one either way -
     * that lookup only touches the local ProductMap table, never Shopify,
     * so it's safe to run in dry-run mode too and gives --dry-run something
     * concrete to show instead of just a count.
     *
     * @param  array<int, array<string, mixed>>  $validusProducts
     * @return array{groupKey: string, title: string, action: string, shopifyProductId: ?string, variants: array<int, array{sku: string, vintage: string, format: string, price: string}>}
     */
    protected function syncGroup(string $groupKey, array $validusProducts, bool $dryRun): array
    {
        $title = $validusProducts[0]['name'] ?? $groupKey;

        $years = [];
        $formats = [];
        $variantsBySku = [];

        foreach ($validusProducts as $validusProduct) {
            $sku = $validusProduct['code']['code'] ?? (string) $validusProduct['id'];
            $year = (string) ($this->grouping->vintageYear($validusProduct) ?? '');
            $format = $this->formatLabel($validusProduct);
            $price = $this->price($validusProduct);

            $years[] = $year;
            $formats[] = $format;

            $variantsBySku[$sku] = [
                'validusProduct' => $validusProduct,
                'input' => [
                    'sku' => $sku,
                    'price' => $price,
                    'optionValues' => [
                        ['optionName' => $this->vintageOptionName, 'name' => $year],
                        ['optionName' => $this->formatOptionName, 'name' => $format],
                    ],
                ],
                'preview' => ['sku' => $sku, 'vintage' => $year, 'format' => $format, 'price' => $price],
            ];
        }

        $existingProductId = ProductMap::query()
            ->where('validus_code', 'like', $groupKey.'%')
            ->whereNotNull('shopify_product_id')
            ->value('shopify_product_id');

        $groupPreview = [
            'groupKey' => $groupKey,
            'title' => $title,
            'action' => $existingProductId ? 'update' : 'create',
            'shopifyProductId' => $existingProductId,
            'variants' => array_map(fn (array $v) => $v['preview'], array_values($variantsBySku)),
        ];

        if ($dryRun) {
            return $groupPreview;
        }

        $upserted = $this->shopify->upsertProduct([
            'title' => $title,
            'options' => [
                ['name' => $this->vintageOptionName, 'values' => array_values(array_unique($years))],
                ['name' => $this->formatOptionName, 'values' => array_values(array_unique($formats))],
            ],
            'variants' => array_map(fn (array $v) => $v['input'], array_values($variantsBySku)),
            'shopifyProductId' => $existingProductId,
        ]);

        $this->storeMapping($upserted, $variantsBySku);
        $this->syncInventory($upserted, $variantsBySku);

        return $groupPreview;
    }

    /**
     * @param  array{productId: string, variants: array<int, array{id: string, sku: string}>}  $upserted
     * @param  array<string, array{validusProduct: array<string, mixed>}>  $variantsBySku
     */
    protected function storeMapping(array $upserted, array $variantsBySku): void
    {
        foreach ($upserted['variants'] as $shopifyVariant) {
            $sku = $shopifyVariant['sku'];

            if (! isset($variantsBySku[$sku])) {
                continue;
            }

            $validusProduct = $variantsBySku[$sku]['validusProduct'];

            ProductMap::query()->updateOrCreate(
                ['validus_id' => (string) $validusProduct['id']],
                [
                    'validus_code' => $sku,
                    'shopify_product_id' => $upserted['productId'],
                    'shopify_variant_id' => $shopifyVariant['id'],
                ],
            );
        }
    }

    /**
     * Only pushes qtyInStock for variants someone has already flipped to
     * "tracked" in Shopify - new imports stay untracked by default.
     *
     * @param  array{productId: string, variants: array<int, array{id: string, sku: string}>}  $upserted
     * @param  array<string, array{validusProduct: array<string, mixed>}>  $variantsBySku
     */
    protected function syncInventory(array $upserted, array $variantsBySku): void
    {
        if (! $this->locationId) {
            return;
        }

        $variantIds = array_column($upserted['variants'], 'id');

        if (empty($variantIds)) {
            return;
        }

        $inventoryState = $this->shopify->variantInventoryState($variantIds);

        foreach ($upserted['variants'] as $shopifyVariant) {
            $state = $inventoryState[$shopifyVariant['id']] ?? null;

            if (! $state || ! $state['tracked'] || ! $state['inventoryItemId']) {
                continue;
            }

            $validusProduct = $variantsBySku[$shopifyVariant['sku']]['validusProduct'] ?? null;

            if (! $validusProduct) {
                continue;
            }

            $this->shopify->setInventoryQuantity(
                $state['inventoryItemId'],
                $this->locationId,
                (int) ($validusProduct['qtyInStock'] ?? 0),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $validusProduct
     */
    protected function price(array $validusProduct): string
    {
        $net = Arr::get($validusProduct, 'price.discountedPrice') ?? Arr::get($validusProduct, 'price.fullPrice', 0);

        if ($this->pricesIncludeTax) {
            $rate = (float) Arr::get($validusProduct, 'tax.rate', 0);
            $net = $net * (1 + $rate / 100);
        }

        return number_format((float) $net, 2, '.', '');
    }

    /**
     * Bottle size label in the "150cl" style. bottleCapacity is assumed to
     * be in liters - Validus doesn't say so explicitly for every
     * measureUnit value, confirm with the customer before go-live.
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
            : number_format($centiliters, 1, ',', '');

        return "{$formatted}cl";
    }
}
