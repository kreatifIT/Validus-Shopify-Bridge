<?php

namespace Kreatif\ValidusShopifyBridge\Events;

use Throwable;

/**
 * Fired when ExportOrderToValidusJob exhausts its retries. Extension point
 * for the consuming app to wire up alerting (Slack, email, ...) - this
 * package deliberately doesn't send notifications itself.
 */
class OrderExportFailed
{
    /**
     * @param  string  $orderNumber  The human-readable order number (e.g. "#A2"), for
     *                                notifications/logs - $shopifyOrderId alone isn't
     *                                what anyone would search Shopify Admin for.
     */
    public function __construct(
        public string $shopifyOrderId,
        public string $orderNumber,
        public Throwable $exception,
    ) {}
}
