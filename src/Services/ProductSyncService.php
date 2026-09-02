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
    ) {}

    /**
     * @return array{groups: int, variants: int, dryRun: bool}
     */
    public function run(bool $dryRun = false): array
    {
        $groups = collect($this->validus->getProducts())
            ->groupBy(fn (array $product) => $this->grouping->groupKey($product));

        $variantCount = 0;

        foreach ($groups as $groupKey => $validusProducts) {
            $this->syncGroup((string) $groupKey, $validusProducts->all(), $dryRun);
            $variantCount += $validusProducts->count();
        }

        return ['groups' => $groups->count(), 'variants' => $variantCount, 'dryRun' => $dryRun];
    }

    /**
     * @param  array<int, array<string, mixed>>  $validusProducts
     */
    protected function syncGroup(string $groupKey, array $validusProducts, bool $dryRun): void
    {
        $title = $validusProducts[0]['name'] ?? $groupKey;

        $years = [];
        $formats = [];
        $variantsBySku = [];

        foreach ($validusProducts as $validusProduct) {
            $sku = $validusProduct['code']['code'] ?? (string) $validusProduct['id'];
            $year = (string) ($this->grouping->vintageYear($validusProduct) ?? '');
            $format = $this->formatLabel($validusProduct);

            $years[] = $year;
            $formats[] = $format;

            $variantsBySku[$sku] = [
                'validusProduct' => $validusProduct,
                'input' => [
                    'sku' => $sku,
                    'price' => $this->price($validusProduct),
                    'optionValues' => [
                        ['optionName' => 'Jahrgang', 'name' => $year],
                        ['optionName' => 'Format', 'name' => $format],
                    ],
                ],
            ];
        }

        if ($dryRun) {
            return;
        }

        $existingProductId = ProductMap::query()
            ->where('validus_code', 'like', $groupKey.'%')
            ->whereNotNull('shopify_product_id')
            ->value('shopify_product_id');

        $upserted = $this->shopify->upsertProduct([
            'title' => $title,
            'options' => [
                ['name' => 'Jahrgang', 'values' => array_values(array_unique($years))],
                ['name' => 'Format', 'values' => array_values(array_unique($formats))],
            ],
            'variants' => array_map(fn (array $v) => $v['input'], array_values($variantsBySku)),
            'shopifyProductId' => $existingProductId,
        ]);

        $this->storeMapping($upserted, $variantsBySku);
        $this->syncInventory($upserted, $variantsBySku);
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
     * Only pushes qtyInStock for variants Iris has already flipped to
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
     * Bottle size label, matching the "150cl" convention already used for
     * variants imported directly from Shopify on this site. bottleCapacity
     * is assumed to be in liters (Validus doesn't say so explicitly for
     * measureUnit "pz" - confirm with Roman before go-live).
     *
     * @param  array<string, mixed>  $validusProduct
     */
    protected function formatLabel(array $validusProduct): string
    {
        $capacity = Arr::get($validusProduct, 'code.bottleCapacity');

        if ($capacity === null) {
            return 'unbekannt';
        }

        $centiliters = $capacity * 100;

        $formatted = floor($centiliters) == $centiliters
            ? (string) (int) $centiliters
            : number_format($centiliters, 1, ',', '');

        return "{$formatted}cl";
    }
}
