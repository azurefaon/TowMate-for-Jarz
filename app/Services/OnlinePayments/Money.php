<?php

namespace App\Services\OnlinePayments;

use InvalidArgumentException;

/** Peso <-> centavo conversion done on decimal strings, never on raw float multiplication. */
final class Money
{
    public static function pesosToCentavos(string|int|float $pesos): int
    {
        if (! is_numeric($pesos)) {
            throw new InvalidArgumentException('Amount is not numeric.');
        }

        $fixed = number_format(round((float) $pesos, 2), 2, '.', '');
        $centavos = (int) str_replace('.', '', $fixed);

        if ($centavos <= 0) {
            throw new InvalidArgumentException('Amount must be positive.');
        }

        return $centavos;
    }

    public static function centavosToPesos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
