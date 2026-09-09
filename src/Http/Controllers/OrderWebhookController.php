<?php

namespace Kreatif\ValidusShopifyBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Kreatif\ValidusShopifyBridge\Jobs\ExportOrderToValidusJob;
use Throwable;

class OrderWebhookController
{
    /**
     * Registered in Shopify Admin against the "orders/paid" topic - not
     * "orders/create" - per Validus' requirement that orders are only
     * reported once payment has gone through.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        $this->recordWebhookPayload($payload);

        ExportOrderToValidusJob::dispatch($payload);

        return response()->json(['received' => true]);
    }

    /**
     * Writes the raw "orders/paid" webhook payload, as Shopify sent it, to
     * <disk>/<directory>/<orderId>.json (a redelivery overwrites it) - the
     * counterpart to ValidusClient's request log, so it's on hand to
     * compare Shopify's original order data against what actually got sent
     * to Validus. Only runs once VerifyShopifyWebhookSignature has already
     * confirmed this is a genuine Shopify request. Best-effort: a
     * filesystem problem here must not stop the actual export.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function recordWebhookPayload(array $payload): void
    {
        $disk = config('validus-shopify.order_export.webhook_log_disk');

        if (! $disk) {
            return;
        }

        $directory = config('validus-shopify.order_export.webhook_log_directory', 'validus-order-webhooks');
        $orderId = $payload['id'] ?? 'unknown';

        try {
            Storage::disk($disk)->put(
                "{$directory}/{$orderId}.json",
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
