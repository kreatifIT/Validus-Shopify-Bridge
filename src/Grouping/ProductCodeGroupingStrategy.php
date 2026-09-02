<?php

namespace Kreatif\ValidusShopifyBridge\Grouping;

/**
 * Kellerei St. Michael's confirmed code.code format (8 digits, e.g.
 * "56070025"): first N digits identify the product ("56" = Sauvignon Sanct
 * Valentin, "99" = Appius), last N digits are the vintage year (2-digit,
 * century prefix assumed). The middle digits are explicitly ignored per the
 * customer - despite looking like bottle size/case quantity in the examples
 * they gave, the customer said not to parse them; bottle size instead comes
 * from the separate code.bottleCapacity/measureUnit API fields.
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
