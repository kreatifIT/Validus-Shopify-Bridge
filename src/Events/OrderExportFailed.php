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
    public function __construct(
        public string $shopifyOrderId,
        public Throwable $exception,
    ) {}
}
