<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Normalises writes to NUMERIC(…, 2) columns as exact two-place decimal strings ("1250.50").
 * Floats are refused on write, because a float cannot be trusted to hold an exact peso amount.
 *
 * Inbound-only on purpose. PostgreSQL already returns NUMERIC as an exact string, and an inbound cast
 * keeps the attribute a plain string even when a Money object was assigned (a two-way cast would
 * cache and return the Money object instead).
 */
class MoneyCast implements CastsInboundAttributes
{
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            throw new InvalidArgumentException("Refusing to store a float in money column [{$key}]; pass a decimal string or Money.");
        }

        return Money::of($value)->toString();
    }
}
