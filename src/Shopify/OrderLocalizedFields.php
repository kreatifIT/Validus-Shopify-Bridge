<?php

namespace Kreatif\ValidusShopifyBridge\Shopify;

use Illuminate\Support\Arr;
use Kreatif\ValidusShopifyBridge\Exceptions\ShopifyApiException;

/**
 * The order's localized checkout fields: the per-country tax credentials
 * Shopify's checkout asks for, such as the Italian codice fiscale
 * (TAX_CREDENTIAL_IT). They are not part of the "orders/paid" webhook
 * payload, so they are looked up through the Admin GraphQL API and added to
 * the payload under PAYLOAD_KEY, where OrderExportService reads them.
 *
 * Only orders with an Italian billing address are looked up - the codice
 * fiscale is the only field the export uses, and every other order stays
 * independent of an extra Shopify call.
 */
class OrderLocalizedFields
{
    /** Key the fields are added under, as [field key => value]. Not a Shopify key. */
    public const PAYLOAD_KEY = '_localized_fields';

    public function __construct(protected ShopifyGraphqlClient $shopify) {}

    /**
     * @param  array<string, mixed>  $shopifyOrder  Raw "orders/paid" webhook payload.
     * @return array<string, mixed>
     *
     * @throws ShopifyApiException
     */
    public function addTo(array $shopifyOrder): array
    {
        if (array_key_exists(self::PAYLOAD_KEY, $shopifyOrder) || Arr::get($shopifyOrder, 'billing_address.country_code') !== 'IT') {
            return $shopifyOrder;
        }

        $orderId = (string) Arr::get($shopifyOrder, 'admin_graphql_api_id', 'gid://shopify/Order/'.$shopifyOrder['id']);
        $shopifyOrder[self::PAYLOAD_KEY] = $this->fetch($orderId);

        return $shopifyOrder;
    }

    /**
     * @param  string  $orderId  The order's GraphQL id (gid://shopify/Order/…).
     * @return array<string, string> Filled fields only, keyed like TAX_CREDENTIAL_IT.
     *
     * @throws ShopifyApiException
     */
    public function fetch(string $orderId): array
    {
        $query = <<<'GRAPHQL'
            query OrderLocalizedFields($id: ID!) {
                order(id: $id) {
                    localizedFields(first: 20) {
                        nodes { key value }
                    }
                }
            }
        GRAPHQL;

        $nodes = Arr::get($this->shopify->query($query, ['id' => $orderId]), 'order.localizedFields.nodes', []);

        return collect($nodes)
            ->filter(fn (array $field): bool => trim((string) ($field['value'] ?? '')) !== '')
            ->mapWithKeys(fn (array $field): array => [$field['key'] => (string) $field['value']])
            ->all();
    }
}
