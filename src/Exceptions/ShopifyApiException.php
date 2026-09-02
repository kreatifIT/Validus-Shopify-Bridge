<?php

namespace Kreatif\ValidusShopifyBridge\Exceptions;

use RuntimeException;

class ShopifyApiException extends RuntimeException
{
    public static function requestFailed(int $status, string $body): self
    {
        return new self("Shopify GraphQL request failed with status {$status}: {$body}");
    }

    public static function graphqlErrors(array $errors): self
    {
        return new self('Shopify GraphQL request returned errors: '.json_encode($errors));
    }

    public static function userErrors(string $mutation, array $errors): self
    {
        return new self("Shopify mutation [{$mutation}] returned userErrors: ".json_encode($errors));
    }
}
