<?php

namespace App\Services\OnlinePayments;

use RuntimeException;

/**
 * The provider could not do what we asked. The message is ours, never the provider's raw body
 * or anything that could carry a credential.
 *
 * `diagnostic` is operator-only: the STRUCTURE of the response (key paths and types) plus the provider's
 * error code/detail with anything key-shaped redacted. It is shown by the pilot command and nowhere else.
 */
class GatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $diagnostic = null,
    ) {
        parent::__construct($message);
    }
}
