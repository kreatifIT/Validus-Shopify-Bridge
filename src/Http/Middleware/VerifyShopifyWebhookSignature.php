<?php

namespace Kreatif\ValidusShopifyBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyShopifyWebhookSignature
{
    public function handle(Request $request, Closure $next)
    {
        if (config('validus-shopify.shopify.ignore_webhook_integrity_check', false)) {
            return $next($request);
        }

        $hmacHeader = $request->header('X-Shopify-Hmac-Sha256');
        $secret = config('validus-shopify.shopify.webhook_secret');

        if (! $hmacHeader || ! $secret) {
            return response()->json(['error' => true], 403);
        }

        $calculatedHmac = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if (! hash_equals($calculatedHmac, $hmacHeader)) {
            return response()->json(['error' => true], 403);
        }

        return $next($request);
    }
}
