<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait GeneratesPublicCode
{
    protected static function nextPublicCode(string $column): string
    {
        $model = new static();
        $table = $model->getTable();

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT);
        }

        $highest = DB::table($table)
            ->whereNotNull($column)
            ->pluck($column)
            ->map(fn($value) => (int) preg_replace('/\D+/', '', (string) $value))
            ->max() ?? 0;

        return str_pad((string) ($highest + 1), 7, '0', STR_PAD_LEFT);
    }
}
