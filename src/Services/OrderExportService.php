<?php

namespace Kreatif\ValidusShopifyBridge\Services;

use Illuminate\Support\Arr;
use Kreatif\ValidusShopifyBridge\Exceptions\MissingProductMappingException;
use Kreatif\ValidusShopifyBridge\Models\ProductMap;

/**
 * Builds the JSON payload ValidusClient::createOrder() expects from a
 * Shopify "orders/paid" webhook payload (standard REST order object shape).
 *
 * Deliberately left open per the plan, pending Roman/Iris/Georg:
 * - a line item without a variant_id (gift cards, manual draft-order lines,
 *   a possible future voucher line item type) has no ProductMap entry to
 *   resolve, so buildPayload() throws rather than send an incomplete order
 * - Italian customers' required fiscalId (codice fiscale) - Shopify's
 *   checkout doesn't collect this out of the box
 * - additional payment methods beyond what's in payment_code_map (throws too)
 */
class OrderExportService
{
    /**
     * @param  array<string, string>  $paymentCodeMap  Shopify payment_gateway_names entry => Validus paymentCode
     */
    public function __construct(protected array $paymentCodeMap = []) {}

    /**
     * @param  array<string, mixed>  $shopifyOrder  Raw "orders/paid" webhook payload.
     * @return array<string, mixed>
     */
    public function buildPayload(array $shopifyOrder): array
    {
        return [
            'orderId' => (string) $shopifyOrder['id'],
            'orderDate' => substr((string) Arr::get($shopifyOrder, 'created_at', ''), 0, 10),
            'orderNumber' => (string) Arr::get($shopifyOrder, 'name', $shopifyOrder['id']),
            'status' => 'confirmed',
            'currency' => Arr::get($shopifyOrder, 'currency', 'EUR'),
            'customer' => $this->customer($shopifyOrder),
            'items' => $this->items($shopifyOrder),
            'shipping' => $this->shipping($shopifyOrder),
            'productsNet' => $this->money(Arr::get($shopifyOrder, 'total_line_items_price', 0)),
            'taxAmount' => $this->money(Arr::get($shopifyOrder, 'total_tax', 0)),
            'discountAmount' => $this->money(Arr::get($shopifyOrder, 'total_discounts', 0)),
            'grandTotal' => $this->money(Arr::get($shopifyOrder, 'total_price', 0)),
            'payments' => $this->payments($shopifyOrder),
            'taxBreakdown' => $this->taxBreakdown($shopifyOrder),
            'notes' => Arr::get($shopifyOrder, 'note'),
        ];
    }

    /**
     * @param  array<string, mixed>  $shopifyOrder
     * @return array<string, mixed>
     */
    protected function customer(array $shopifyOrder): array
    {
        $customer = Arr::get($shopifyOrder, 'customer', []);
        $billing = Arr::get($shopifyOrder, 'billing_address', []);
        $shipping = Arr::get($shopifyOrder, 'shipping_address', $billing);

        return [
            'customerId' => (string) Arr::get($customer, 'id', ''),
            'countryCode' => Arr::get($billing, 'country_code'),
            'type' => 'person',
            'companyName' => null,
            'vatNumber' => null,
            // TODO: Codice Fiscale for Italian customers - not collected by
            // Shopify's default checkout, needs a custom checkout field
            // before this can be filled in for IT orders.
            'fiscalId' => null,
            'firstName' => Arr::get($customer, 'first_name', Arr::get($billing, 'first_name')),
            'lastName' => Arr::get($customer, 'last_name', Arr::get($billing, 'last_name')),
            'email' => Arr::get($shopifyOrder, 'email', Arr::get($customer, 'email')),
            'phone' => Arr::get($shopifyOrder, 'phone', Arr::get($customer, 'phone')),
            'billingAddress' => $this->address($billing),
            'shippingAddress' => $this->address($shipping),
        ];
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    protected function address(array $address): array
    {
        return [
            'street' => trim(Arr::get($address, 'address1', '').' '.Arr::get($address, 'address2', '')),
            'zipCode' => Arr::get($address, 'zip'),
            'city' => Arr::get($address, 'city'),
            'state' => Arr::get($address, 'province'),
            'countryCode' => Arr::get($address, 'country_code'),
        ];
    }

    /**
     * @param  array<string, mixed>  $shopifyOrder
     * @return array<int, array<string, mixed>>
     */
    protected function items(array $shopifyOrder): array
    {
        $items = [];

        foreach (Arr::get($shopifyOrder, 'line_items', []) as $index => $lineItem) {
            $variantId = (string) Arr::get($lineItem, 'variant_id', '');
            $map = ProductMap::findByShopifyVariantId($variantId);

            if (! $map) {
                throw MissingProductMappingException::forVariant($variantId);
            }

            $items[] = [
                'lineNumber' => $index + 1,
                'productId' => (int) $map->validus_id,
                'code' => $map->validus_code,
                'description' => Arr::get($lineItem, 'name'),
                'quantity' => (int) Arr::get($lineItem, 'quantity', 1),
                'unitPriceNet' => $this->money(Arr::get($lineItem, 'price', 0)),
                'discountPercent' => 0.0,
                'vatRate' => $this->lineItemVatRate($lineItem),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $lineItem
     */
    protected function lineItemVatRate(array $lineItem): float
    {
        $taxLine = Arr::first(Arr::get($lineItem, 'tax_lines', []));

        return $taxLine ? round((float) Arr::get($taxLine, 'rate', 0) * 100, 2) : 0.0;
    }

    /**
     * @param  array<string, mixed>  $shopifyOrder
     * @return array<string, mixed>
     */
    protected function shipping(array $shopifyOrder): array
    {
        $shippingLine = Arr::first(Arr::get($shopifyOrder, 'shipping_lines', []));

        return [
            'shippingCost' => $this->money(Arr::get($shippingLine, 'price', 0)),
            'shippingVatRate' => $shippingLine
                ? round((float) Arr::get(Arr::first(Arr::get($shippingLine, 'tax_lines', [])), 'rate', 0) * 100, 2)
                : 0.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $shopifyOrder
     * @return array<int, array<string, mixed>>
     */
    protected function payments(array $shopifyOrder): array
    {
        $gateway = Arr::first(Arr::get($shopifyOrder, 'payment_gateway_names', []));

        if (! $gateway || ! isset($this->paymentCodeMap[$gateway])) {
            throw new \RuntimeException("No Validus payment code configured for Shopify gateway [{$gateway}]. Add it to config('validus-shopify.payment_code_map').");
        }

        return [[
            'paymentCode' => $this->paymentCodeMap[$gateway],
            'paymentReference' => (string) Arr::get($shopifyOrder, 'checkout_id', $shopifyOrder['id']),
            'paidAmount' => $this->money(Arr::get($shopifyOrder, 'total_price', 0)),
            'paymentDate' => substr((string) Arr::get($shopifyOrder, 'processed_at', Arr::get($shopifyOrder, 'created_at', '')), 0, 10),
        ]];
    }

    /**
     * Sums Shopify's per-line tax_lines by rate into Validus' taxBreakdown
     * shape (one entry per distinct VAT rate in the order).
     *
     * @param  array<string, mixed>  $shopifyOrder
     * @return array<int, array<string, mixed>>
     */
    protected function taxBreakdown(array $shopifyOrder): array
    {
        $byRate = [];

        foreach (Arr::get($shopifyOrder, 'tax_lines', []) as $taxLine) {
            $rate = round((float) Arr::get($taxLine, 'rate', 0) * 100, 2);
            $tax = (float) Arr::get($taxLine, 'price', 0);

            $byRate[$rate] ??= ['vat' => $rate, 'taxable' => 0.0, 'tax' => 0.0];
            $byRate[$rate]['tax'] += $tax;
        }

        // Validus expects "taxable" (net base) per rate; Shopify's order-level
        // tax_lines don't give that directly, so derive it from the tax
        // amount and rate (taxable = tax / (rate / 100)).
        foreach ($byRate as $rate => &$entry) {
            $entry['taxable'] = $rate > 0 ? round($entry['tax'] / ($rate / 100), 2) : 0.0;
            $entry['tax'] = round($entry['tax'], 2);
        }

        return array_values($byRate);
    }

    protected function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
