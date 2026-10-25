<?php

namespace App\Exceptions;

use RuntimeException;

/** A refused next-task queue action, carrying the HTTP status and a stable code for clients. */
class QueueOfferException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly string $reason = 'refused')
    {
        parent::__construct($message);
    }
}
