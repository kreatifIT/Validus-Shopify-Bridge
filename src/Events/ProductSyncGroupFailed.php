<?php

namespace Kreatif\ValidusShopifyBridge\Events;

use Throwable;

/**
 * Fired once per Shopify product group that sync-products failed to
 * create/update - the sync itself doesn't abort, so a data problem specific
 * to one product (e.g. two distinct Validus products colliding on the same
 * vintage/format combination) doesn't block every other product from being
 * synced. Not handled by this package itself: bind a listener in the
 * consuming app to turn this into an actual alert (email, Slack, ...).
 */
class ProductSyncGroupFailed
{
    /**
     * @param  array<int, string>  $skus  Validus product codes (code.code) in the failed group.
     */
    public function __construct(
        public string $groupKey,
        public string $title,
        public array $skus,
        public Throwable $exception,
    ) {}
}
