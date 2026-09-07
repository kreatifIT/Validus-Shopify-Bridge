<?php

namespace Kreatif\ValidusShopifyBridge\Services;

use Illuminate\Support\Arr;
use Kreatif\ValidusShopifyBridge\Exceptions\MissingProductMappingException;
use Kreatif\ValidusShopifyBridge\Models\ProductMap;

/**
 * Builds the JSON payload ValidusClient::createOrder() expects from a
 * Shopify "orders/paid" webhook payload (standard REST order object shape).
 *
 * A discount that targets a specific product reduces that product's own
 * discountPercent; a cart-wide discount (a code or automatic discount with
 * target_selection "all") is reported as its own position instead, with a
 * negative unitPriceNet and no product code - see cartWideDiscountItems().
 *
 * Deliberately left open, to be resolved with the customer per install
 * rather than guessed at generically:
 * - a line item that HAS a Shopify variant_id but no ProductMap entry - a
 *   real product Shopify knows about that was never (or not yet) imported
 *   from Validus - throws rather than sending an incomplete order. A line
 *   item without any variant_id at all (gift cards, manual draft-order
 *   lines, a voucher) is not an error: Validus accepts a line item without
 *   a product code, so it's reported with productId/code left null instead.
 * - Italian customers' fiscalId (codice fiscale) is always sent as null -
 *   Shopify's checkout doesn't collect it, and it's optional on Validus'
 *   side, so this is not a defect to fix, just a known limitation.
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
            // Codice Fiscale for Italian customers - not collected by
            // Shopify's default checkout. Optional on Validus' side, so
            // always sending null is fine as-is.
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
        $discountApplications = Arr::get($shopifyOrder, 'discount_applications', []);
        $items = [];

        foreach (Arr::get($shopifyOrder, 'line_items', []) as $index => $lineItem) {
            $variantId = (string) Arr::get($lineItem, 'variant_id', '');

            if ($variantId === '') {
                // No Shopify variant behind this line at all (gift card,
                // manual draft-order line, a voucher) - Validus accepts a
                // line item without a product code, so report it
                // descriptively rather than failing the whole order.
                $items[] = $this->item($index, null, null, $lineItem, $discountApplications);

                continue;
            }

            $map = ProductMap::findByShopifyVariantId($variantId);

            if (! $map) {
                throw MissingProductMappingException::forVariant($variantId);
            }

            $items[] = $this->item($index, (int) $map->validus_id, $map->validus_code, $lineItem, $discountApplications);
        }

        foreach ($this->cartWideDiscountItems($shopifyOrder, count($items)) as $discountItem) {
            $items[] = $discountItem;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $lineItem
     * @param  array<int, array<string, mixed>>  $discountApplications  The order's discount_applications, for lineItemDiscountPercent() to tell a product-specific discount (reflected here) from a cart-wide one (reported as its own position by cartWideDiscountItems() instead).
     * @return array<string, mixed>
     */
    protected function item(int $index, ?int $productId, ?string $code, array $lineItem, array $discountApplications): array
    {
        return [
            'lineNumber' => $index + 1,
            'productId' => $productId,
            'code' => $code,
            'description' => Arr::get($lineItem, 'name'),
            'quantity' => (int) Arr::get($lineItem, 'quantity', 1),
            'unitPriceNet' => $this->money(Arr::get($lineItem, 'price', 0)),
            'discountPercent' => $this->lineItemDiscountPercent($lineItem, $discountApplications),
            'vatRate' => $this->lineItemVatRate($lineItem),
        ];
    }

    /**
     * Shopify's line_items[].price is the ORIGINAL unit price, before any
     * discount - the actual amount taken off is in discount_allocations
     * (one entry per discount application that touched this line, e.g. a
     * discount code plus an automatic discount stacking). unitPriceNet
     * stays the original price; this percentage is what tells Validus how
     * much of it to take off.
     *
     * Only counts allocations from a discount targeting THIS product
     * specifically (target_selection "entitled"/"explicit") - a cart-wide
     * discount (target_selection "all") is reported as its own position by
     * cartWideDiscountItems() instead of being folded into every line, so
     * it's excluded here to avoid double-counting.
     *
     * @param  array<string, mixed>  $lineItem
     * @param  array<int, array<string, mixed>>  $discountApplications
     */
    protected function lineItemDiscountPercent(array $lineItem, array $discountApplications): float
    {
        $grossTotal = (float) Arr::get($lineItem, 'price', 0) * (int) Arr::get($lineItem, 'quantity', 1);

        if ($grossTotal <= 0) {
            return 0.0;
        }

        $discountTotal = collect(Arr::get($lineItem, 'discount_allocations', []))
            ->reject(fn (array $allocation) => $this->isCartWideDiscount($allocation, $discountApplications))
            ->sum(fn (array $allocation) => (float) Arr::get($allocation, 'amount', 0));

        return round(($discountTotal / $grossTotal) * 100, 2);
    }

    /**
     * @param  array<string, mixed>  $allocation
     * @param  array<int, array<string, mixed>>  $discountApplications
     */
    protected function isCartWideDiscount(array $allocation, array $discountApplications): bool
    {
        $application = Arr::get($discountApplications, (int) Arr::get($allocation, 'discount_application_index'));

        return (bool) $application && Arr::get($application, 'target_selection') === 'all';
    }

    /**
     * A discount code or automatic discount that applies to the whole cart
     * (target_selection "all", e.g. "10% off your order") is reported as
     * its own position - a negative unitPriceNet, no product code - rather
     * than spread across every product's discountPercent. One position per
     * such discount_applications entry (an order can combine more than one,
     * e.g. a discount code plus an automatic discount).
     *
     * @param  array<string, mixed>  $shopifyOrder
     * @return array<int, array<string, mixed>>
     */
    protected function cartWideDiscountItems(array $shopifyOrder, int $lineItemCount): array
    {
        $discountApplications = Arr::get($shopifyOrder, 'discount_applications', []);
        $lineItems = Arr::get($shopifyOrder, 'line_items', []);
        $vatRate = $this->lineItemVatRate(Arr::first($lineItems, default: []));

        $items = [];

        foreach ($discountApplications as $index => $application) {
            if (Arr::get($application, 'target_selection') !== 'all') {
                continue;
            }

            $amount = collect($lineItems)
                ->flatMap(fn (array $lineItem) => Arr::get($lineItem, 'discount_allocations', []))
                ->filter(fn (array $allocation) => (int) Arr::get($allocation, 'discount_application_index') === $index)
                ->sum(fn (array $allocation) => (float) Arr::get($allocation, 'amount', 0));

            if ($amount <= 0) {
                continue;
            }

            $label = Arr::get($application, 'title') ?: Arr::get($application, 'code') ?: 'Rabatt';

            $items[] = [
                'lineNumber' => $lineItemCount + count($items) + 1,
                'productId' => null,
                'code' => null,
                'description' => "Rabatt: {$label}",
                'quantity' => 1,
                'unitPriceNet' => $this->money(-$amount),
                'discountPercent' => 0.0,
                'vatRate' => $vatRate,
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
