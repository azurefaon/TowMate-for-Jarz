{{-- Two small groups share one tab bar. Each tab is its own page with its own route, controller and permission. --}}
@php
    $groups = [
        'crew' => ['label' => 'Crew & Driver Records', 'tabs' => [
            ['Job Crew', 'superadmin.finance.crew.index', 'superadmin.finance.crew.*'],
            ['Driver Records', 'superadmin.finance.driver-records.index', 'superadmin.finance.driver-records.*'],
        ]],
        'commission' => ['label' => 'Commissions', 'tabs' => [
            ['Draft Breakdown', 'superadmin.finance.commission.index', 'superadmin.finance.commission.*'],
            ['Statements & Payouts', 'superadmin.finance.statements.index', 'superadmin.finance.statements.*'],
        ]],
    ];
    $g = $groups[$group];
    // Carry the chosen unit across tabs; Job Crew's week becomes Driver Records' Monday–Saturday period.
    $carry = array_filter(['unit_id' => request()->query('unit_id')], fn ($v) => filled($v) && ctype_digit((string) $v));
    $week = request()->query('week');
    $weekOk = is_string($week) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week);
@endphp
<nav class="fin-tabs" aria-label="{{ $g['label'] }}" data-fin-tabs="{{ $group }}">
    @foreach ($g['tabs'] as [$text, $routeName, $match])
        @php
            $active = request()->routeIs($match);
            $params = $carry;
            if (! $active && $routeName === 'superadmin.finance.driver-records.index' && $weekOk) {
                try {
                    $monday = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $week, config('finance.timezone'))->startOfWeek(\Carbon\CarbonImmutable::MONDAY);
                    $params += ['from' => $monday->toDateString(), 'to' => $monday->addDays(5)->toDateString()];
                } catch (\Throwable) {
                }
            }
            $from = request()->query('from');
            if (! $active && $routeName === 'superadmin.finance.crew.index' && is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                $params += ['week' => $from];
            }
            if (! $active && $routeName === 'superadmin.finance.commission.index' && $weekOk) {
                $params += ['week' => $week];
            }
        @endphp
        <a href="{{ route($routeName, $params) }}" @class(['fin-tab', 'is-active' => $active]) @if ($active) aria-current="page" @endif>{{ $text }}</a>
    @endforeach
</nav>