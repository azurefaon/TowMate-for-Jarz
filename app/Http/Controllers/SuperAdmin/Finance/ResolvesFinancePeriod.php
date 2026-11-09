<?php

namespace App\Http\Controllers\SuperAdmin\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Shared ?from=&to= handling for the finance pages. Defaults to the current Monday–Saturday week (Asia/Manila). */
trait ResolvesFinancePeriod
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    protected function resolvePeriod(Request $request): array
    {
        $tz = config('finance.timezone');
        $parse = function (mixed $value) use ($tz): ?CarbonImmutable {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return null;
            }
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $value, $tz)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        };

        $from = $parse($request->query('from'));
        $to = $parse($request->query('to'));

        if (! $from && ! $to) {
            $from = CarbonImmutable::now($tz)->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
            $to = $from->addDays((int) config('finance.week_length_days') - 1);
            // On a Sunday (outside the Mon–Sat week) include today so Sunday activity is still visible by default.
            $today = CarbonImmutable::now($tz)->startOfDay();
            if ($today->greaterThan($to)) {
                $to = $today;
            }
        }
        $from ??= $to;
        $to ??= $from;
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    protected function periodLabel(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->equalTo($to) ? $from->format('M j, Y') : $from->format('M j, Y') . ' – ' . $to->format('M j, Y');
    }
}
