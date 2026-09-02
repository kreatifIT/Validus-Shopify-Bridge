<?php

namespace Kreatif\ValidusShopifyBridge\Grouping;

/**
 * Default grouping strategy for a common Validus code.code format: a fixed
 * number of leading digits identify the product, a fixed number of trailing
 * digits are the vintage year (2-digit, century prefix assumed), and
 * anything in between is ignored - bottle size/format is expected to come
 * from the separate code.bottleCapacity/measureUnit API fields instead, not
 * from parsing the code further. Confirm the exact digit layout with the
 * customer per install; a customer whose Validus code scheme doesn't fit
 * this pattern at all can supply their own VariantGroupingStrategy instead.
 */
class ProductCodeGroupingStrategy implements VariantGroupingStrategy
{
    public function __construct(
        protected int $productCodeLength = 2,
        protected int $yearCodeLength = 2,
        protected string $yearCenturyPrefix = '20',
    ) {}

    public function groupKey(array $validusProduct): string
    {
        $code = (string) ($validusProduct['code']['code'] ?? '');

        return substr($code, 0, $this->productCodeLength) ?: (string) ($validusProduct['id'] ?? '');
    }

    public function vintageYear(array $validusProduct): ?int
    {
        // code.year comes back structured from Validus (confirmed in the API
        // sample) - prefer it, only fall back to parsing the code string if
        // it's ever missing.
        if ($year = $validusProduct['code']['year'] ?? null) {
            return (int) $year;
        }

        $code = (string) ($validusProduct['code']['code'] ?? '');

        if (strlen($code) < $this->yearCodeLength) {
            return null;
        }

        $yearSuffix = substr($code, -$this->yearCodeLength);

        return (int) ($this->yearCenturyPrefix.$yearSuffix);
    }
}
