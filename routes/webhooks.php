<?php

use Illuminate\Support\Facades\Route;
use Kreatif\ValidusShopifyBridge\Http\Controllers\OrderWebhookController;
use Kreatif\ValidusShopifyBridge\Http\Middleware\VerifyShopifyWebhookSignature;

Route::post('webhook/order', OrderWebhookController::class)
    ->middleware(VerifyShopifyWebhookSignature::class)
    ->name('validus-shopify.webhook.order');
