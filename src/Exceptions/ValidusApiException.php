<?php

namespace Kreatif\ValidusShopifyBridge\Exceptions;

use RuntimeException;

class ValidusApiException extends RuntimeException
{
    public static function requestFailed(string $endpoint, int $status, string $body): self
    {
        return new self("Validus API request to [{$endpoint}] failed with status {$status}: {$body}");
    }

    public static function unsuccessful(string $endpoint, string $body): self
    {
        return new self("Validus API request to [{$endpoint}] returned success:false: {$body}");
    }
}
