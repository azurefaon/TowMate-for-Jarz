<?php

use App\Exceptions\HomeUnitConflictException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;

function hucQueryException(string $sqlState): QueryException
{
    $pdo = new class($sqlState) extends PDOException
    {
        public function __construct(string $sqlState)
        {
            parent::__construct("SQLSTATE[{$sqlState}]: test");
            $this->code = $sqlState;
        }
    };

    return new QueryException('pgsql', 'select 1', [], $pdo);
}

it('treats deadlocks, serialization failures and lock timeouts as conflicts', function (string $sqlState) {
    expect(HomeUnitConflictException::causedBy(hucQueryException($sqlState)))->toBeTrue();
})->with(['40P01', '40001', '55P03']);

it('finds a deadlock that a nested transaction rethrew as DeadlockException', function () {
    $query = hucQueryException('40P01');
    $nested = new DeadlockException($query->getMessage(), 0, $query);

    expect(HomeUnitConflictException::causedBy($nested))->toBeTrue();
});

it('does not treat other database errors as conflicts', function () {
    expect(HomeUnitConflictException::causedBy(hucQueryException('23505')))->toBeFalse()
        ->and(HomeUnitConflictException::causedBy(new RuntimeException('rule refused')))->toBeFalse();
});

it('is a 409 with a message for the owner', function () {
    $e = new HomeUnitConflictException();

    expect($e->status)->toBe(409)
        ->and($e->getMessage())->toBe('This was changed somewhere else at the same moment. Nothing was saved. Reload to see the latest assignments, then try again.');
});
