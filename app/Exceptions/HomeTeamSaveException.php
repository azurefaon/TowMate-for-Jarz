<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Home Team batch save that was rolled back. status is the HTTP status the
 * endpoint returns: 409 when the team changed since the dialog opened, 422
 * when a requested change is refused by an assignment rule.
 */
class HomeTeamSaveException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
