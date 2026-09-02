<?php

namespace Kreatif\ValidusShopifyBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kreatif\ValidusShopifyBridge\Jobs\ExportOrderToValidusJob;

class OrderWebhookController
{
    /**
     * Registered in Shopify Admin against the "orders/paid" topic - not
     * "orders/create" - per Validus' requirement that orders are only
     * reported once payment has gone through.
     */
    public function __invoke(Request $request): JsonResponse
    {
        ExportOrderToValidusJob::dispatch($request->json()->all());

        return response()->json(['received' => true]);
    }
}
