<?php

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

/**
 * A home-unit change that collided with another change to the same people or
 * units and was rolled back. Owner endpoints answer it with HTTP 409.
 */
class HomeUnitConflictException extends RuntimeException
{
    public const MESSAGE = 'This was changed somewhere else at the same moment. Nothing was saved. Reload to see the latest assignments, then try again.';

    /** Postgres: deadlock_detected, serialization_failure, lock_not_available. */
    protected const SQLSTATES = ['40P01', '40001', '55P03'];

    public function __construct(string $message = self::MESSAGE, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    /**
     * Whether $e is a lock conflict. Laravel rethrows a deadlock inside a
     * nested transaction as DeadlockException, which keeps the original
     * QueryException as its previous exception, so the whole chain is checked.
     */
    public static function causedBy(Throwable $e): bool
    {
        for ($current = $e; $current; $current = $current->getPrevious()) {
            if ($current instanceof QueryException && in_array((string) $current->getCode(), self::SQLSTATES, true)) {
                return true;
            }
        }

        return false;
    }
}
