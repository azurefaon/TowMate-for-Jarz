<?php

namespace App\Models\Finance;

use Illuminate\Support\Str;

/** Human-readable, unique reference codes such as FUND-20261109-7KQ2MZ. */
trait HasFinanceReference
{
    protected static function bootHasFinanceReference(): void
    {
        static::creating(function ($model) {
            if (blank($model->reference_code)) {
                $model->reference_code = static::REFERENCE_PREFIX . '-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
            }
        });
    }
}
