<?php

declare(strict_types=1);

namespace VerifiedNet;

/**
 * An error answer from the API, or a network failure (status 0, errorCode "network_error").
 *
 * errorCode is the API's error code, for example "insufficient_credits", "rate_limited",
 * "unauthorized" or "invalid_request". getCode() returns the HTTP status; apiMessage is the
 * API's message without the status and code prefix.
 */
class VerifiedError extends \RuntimeException
{
    public readonly string $apiMessage;

    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?string $requestId = null,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct("{$status} {$errorCode}: {$message}", $status);
        $this->apiMessage = $message;
    }
}
