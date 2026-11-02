<?php

namespace App\Services\OnlinePayments;

use RuntimeException;

/**
 * A refused online-payment action: a stable code for clients, an HTTP status, and a message that is safe to show.
 * `diagnostic` (operator-only, sanitized) is never put in an HTTP response.
 */
class OnlinePaymentException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $reason,
        public readonly ?string $diagnostic = null,
    ) {
        parent::__construct($message);
    }
}
