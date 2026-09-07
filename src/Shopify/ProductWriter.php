<?php

namespace Kreatif\ValidusShopifyBridge\Shopify;

use Illuminate\Support\Arr;
use Kreatif\ValidusShopifyBridge\Exceptions\ShopifyApiException;

/**
 * Thin wrapper around the Shopify Admin GraphQL mutations this package
 * needs. `productSet` is Shopify's own recommended mutation for syncing a
 * product with all of its variants from an external system in one call - it
 * creates on first sync and upserts variants (by SKU) on every later sync,
 * so a separate productVariantsBulkUpdate/Create step isn't needed.
 */
class ProductWriter
{
    public function __construct(protected ShopifyGraphqlClient $client) {}

    /**
     * @param  array{title: string, options: array<int, array{name: string, values: array<int, string>}>, variants: array<int, array<string, mixed>>, shopifyProductId: ?string}  $product
     * @return array{productId: string, variants: array<int, array{id: string, sku: string}>}
     */
    public function upsertProduct(array $product): array
    {
        $query = <<<'QUERY'
            mutation ProductSet($input: ProductSetInput!, $synchronous: Boolean!) {
              productSet(input: $input, synchronous: $synchronous) {
                product {
                  id
                  variants(first: 100) {
                    nodes {
                      id
                      sku
                    }
                  }
                }
                userErrors {
                  field
                  message
                }
              }
            }
            QUERY;

        $input = [
            'title' => $product['title'],
            'productOptions' => array_map(fn (array $option) => [
                'name' => $option['name'],
                'values' => array_map(fn (string $value) => ['name' => $value], $option['values']),
            ], $product['options']),
            'variants' => $product['variants'],
        ];

        if ($product['shopifyProductId'] ?? null) {
            $input['id'] = $product['shopifyProductId'];
        }

        $data = $this->client->query($query, ['input' => $input, 'synchronous' => true]);

        if ($errors = Arr::get($data, 'productSet.userErrors')) {
            throw ShopifyApiException::userErrors('productSet', $errors);
        }

        $productId = Arr::get($data, 'productSet.product.id');

        if (! $productId) {
            throw ShopifyApiException::graphqlErrors([$data]);
        }

        return [
            'productId' => $productId,
            'variants' => Arr::get($data, 'productSet.product.variants.nodes', []),
        ];
    }

    /**
     * Only called for variants someone has already flipped to "tracked" in
     * Shopify - new imports stay untracked by default (see config).
     */
    public function setInventoryQuantity(string $inventoryItemId, string $locationId, int $quantity): void
    {
        $query = <<<'QUERY'
            mutation InventorySetQuantities($input: InventorySetQuantitiesInput!) {
              inventorySetQuantities(input: $input) {
                userErrors {
                  field
                  message
                }
              }
            }
            QUERY;

        $data = $this->client->query($query, [
            'input' => [
                'name' => 'available',
                'reason' => 'correction',
                'ignoreCompareQuantity' => true,
                'quantities' => [[
                    'inventoryItemId' => $inventoryItemId,
                    'locationId' => $locationId,
                    'quantity' => $quantity,
                ]],
            ],
        ]);

        if ($errors = Arr::get($data, 'inventorySetQuantities.userErrors')) {
            throw ShopifyApiException::userErrors('inventorySetQuantities', $errors);
        }
    }

    /**
     * Turns on inventory tracking for an item that isn't tracked yet. Needed
     * before deactivateVariant() can rely on inventoryPolicy/stock to make a
     * normally-untracked variant unpurchasable - Shopify only enforces
     * "deny overselling" for tracked items, an untracked one is always
     * purchasable regardless of inventoryPolicy or quantity.
     */
    public function setInventoryTracked(string $inventoryItemId): void
    {
        $query = <<<'QUERY'
            mutation InventoryItemUpdate($id: ID!, $input: InventoryItemUpdateInput!) {
              inventoryItemUpdate(id: $id, input: $input) {
                userErrors {
                  field
                  message
                }
              }
            }
            QUERY;

        $data = $this->client->query($query, ['id' => $inventoryItemId, 'input' => ['tracked' => true]]);

        if ($errors = Arr::get($data, 'inventoryItemUpdate.userErrors')) {
            throw ShopifyApiException::userErrors('inventoryItemUpdate', $errors);
        }
    }

    /**
     * @param  'DENY'|'CONTINUE'  $policy
     */
    public function setVariantInventoryPolicy(string $productId, string $variantId, string $policy): void
    {
        $query = <<<'QUERY'
            mutation ProductVariantsBulkUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
              productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                userErrors {
                  field
                  message
                }
              }
            }
            QUERY;

        $data = $this->client->query($query, [
            'productId' => $productId,
            'variants' => [['id' => $variantId, 'inventoryPolicy' => $policy]],
        ]);

        if ($errors = Arr::get($data, 'productVariantsBulkUpdate.userErrors')) {
            throw ShopifyApiException::userErrors('productVariantsBulkUpdate', $errors);
        }
    }

    /**
     * @param  'ACTIVE'|'ARCHIVED'|'DRAFT'  $status
     */
    public function setProductStatus(string $productId, string $status): void
    {
        $query = <<<'QUERY'
            mutation ProductUpdate($input: ProductInput!) {
              productUpdate(input: $input) {
                userErrors {
                  field
                  message
                }
              }
            }
            QUERY;

        $data = $this->client->query($query, ['input' => ['id' => $productId, 'status' => $status]]);

        if ($errors = Arr::get($data, 'productUpdate.userErrors')) {
            throw ShopifyApiException::userErrors('productUpdate', $errors);
        }
    }

    /**
     * @param  array<int, string>  $variantIds
     * @return array<string, array{inventoryItemId: ?string, tracked: bool}> keyed by variant id
     */
    public function variantInventoryState(array $variantIds): array
    {
        $query = <<<'QUERY'
            query VariantInventoryState($ids: [ID!]!) {
              nodes(ids: $ids) {
                ... on ProductVariant {
                  id
                  inventoryItem {
                    id
                    tracked
                  }
                }
              }
            }
            QUERY;

        $nodes = Arr::get($this->client->query($query, ['ids' => $variantIds]), 'nodes', []);

        $state = [];
        foreach ($nodes as $node) {
            if (! $node) {
                continue;
            }
            $state[$node['id']] = [
                'inventoryItemId' => Arr::get($node, 'inventoryItem.id'),
                'tracked' => Arr::get($node, 'inventoryItem.tracked', false),
            ];
        }

        return $state;
    }

    /**
     * Looks up existing Shopify variants by SKU directly, independent of our
     * own product-map table. Used to adopt a catalog that already has
     * products in Shopify from before this package was introduced (see
     * `validus-shopify:link-existing` and `validus-shopify:diff`) - without
     * this, a SKU that already exists but isn't mapped yet would otherwise
     * get a duplicate product created for it on the next real sync.
     *
     * @param  array<int, string>  $skus
     * @return array<string, array{id: string, price: string, productId: string, productTitle: string}> keyed by SKU
     */
    public function variantsBySku(array $skus): array
    {
        if (empty($skus)) {
            return [];
        }

        $query = <<<'QUERY'
            query VariantsBySku($q: String!) {
              productVariants(first: 100, query: $q) {
                nodes {
                  id
                  sku
                  price
                  product {
                    id
                    title
                  }
                }
              }
            }
            QUERY;

        $found = [];

        foreach (array_chunk(array_values(array_unique($skus)), 20) as $chunk) {
            $search = implode(' OR ', array_map(fn (string $sku) => "sku:{$sku}", $chunk));
            $nodes = Arr::get($this->client->query($query, ['q' => $search]), 'productVariants.nodes', []);

            foreach ($nodes as $node) {
                $found[$node['sku']] = [
                    'id' => $node['id'],
                    'price' => $node['price'],
                    'productId' => Arr::get($node, 'product.id', ''),
                    'productTitle' => Arr::get($node, 'product.title', ''),
                ];
            }
        }

        return $found;
    }
}
