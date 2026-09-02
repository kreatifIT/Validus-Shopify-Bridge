<?php

namespace Kreatif\ValidusShopifyBridge\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kreatif\ValidusShopifyBridge\Clients\ValidusClient;
use Kreatif\ValidusShopifyBridge\Events\OrderExportFailed;
use Kreatif\ValidusShopifyBridge\Models\ExportedOrder;
use Kreatif\ValidusShopifyBridge\Services\OrderExportService;
use Throwable;

class ExportOrderToValidusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 300, 900];

    /**
     * @param  array<string, mixed>  $shopifyOrder  Raw "orders/paid" webhook payload.
     */
    public function __construct(public array $shopifyOrder) {}

    public function handle(OrderExportService $exportService, ValidusClient $validus): void
    {
        $orderId = (string) $this->shopifyOrder['id'];

        // Idempotency net on top of Validus' own orderId check - Shopify
        // does not guarantee exactly-once webhook delivery.
        if (ExportedOrder::alreadyExported($orderId)) {
            return;
        }

        $payload = $exportService->buildPayload($this->shopifyOrder);

        $validus->createOrder($payload);

        ExportedOrder::query()->create([
            'shopify_order_id' => $orderId,
            'exported_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        event(new OrderExportFailed((string) $this->shopifyOrder['id'], $exception));
    }
}
