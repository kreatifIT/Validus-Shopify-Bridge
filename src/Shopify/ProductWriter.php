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
     * Only called for variants Iris has already flipped to "tracked" in
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
}
