<?php

namespace App\Exceptions;

/**
 * A dispatcher team change (Units & Leaders) that collided with another
 * change to the same units, people or borrow records and was rolled back.
 * Answered with HTTP 409.
 */
class TeamAssignmentConflictException extends HomeUnitConflictException
{
    public const DISPATCH_MESSAGE = 'This team assignment changed while you were editing. Nothing was saved. Refresh and try again.';

    public function __construct(string $message = self::DISPATCH_MESSAGE, int $status = 409)
    {
        parent::__construct($message, $status);
    }
}
