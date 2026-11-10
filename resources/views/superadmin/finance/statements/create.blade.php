@extends('layouts.superadmin')

@section('title', 'Prepare Commission Statement')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/finance.css') }}?v={{ filemtime(public_path('superadmin/css/finance.css')) }}">
@endpush

@section('content')
    <div class="ob-page oa-page fin-page" id="ownerStatementCreate">
        <header class="ob-header fin-header">
            <div>
                <h1>Prepare statement: {{ $unit->name }}</h1>
                <p>Week of {{ $week->format('M j') }} – {{ $week->addDays(5)->format('M j, Y') }} (Monday to Saturday).</p>
            </div>
            <div class="fin-header-actions"><a class="oa-btn" href="{{ route('superadmin.finance.statements.index') }}">Back to statements</a></div>
        </header>

        @include('superadmin.finance._flashes')

        @if ($open)
            <div class="fin-flash fin-flash--warning" role="status">
                <i data-lucide="file-pen-line" aria-hidden="true"></i>
                <span>Statement <a href="{{ route('superadmin.finance.statements.show', $open->id) }}">{{ $open->reference_code }}</a> is already {{ $open->statusLabel() }} for this week and unit.
                    {{ $open->status === 'draft' ? 'Calculating again replaces its draft figures with your new selection.' : 'Return it to draft before calculating again.' }}</span>
            </div>
        @endif

        <form method="POST" action="{{ route('superadmin.finance.statements.store') }}" class="oa-card oa-card-body" data-fin-once>
            @csrf
            <input type="hidden" name="week" value="{{ $week->toDateString() }}">
            <input type="hidden" name="unit_id" value="{{ $unit->id }}">
            <div class="oa-card-head"><div>
                <h2 class="oa-card-title"><i data-lucide="list-checks" aria-hidden="true"></i> Jobs to include</h2>
                <p class="oa-card-sub">Jobs already settled by an approved statement cannot be included again. A job with open issues can be included, but the statement cannot be approved until they are resolved.</p>
            </div></div>
            @if ($candidates['jobs'] === [])
                <div class="oa-empty">This unit has no completed jobs in this week.</div>
            @else
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table">
                        <thead><tr><th scope="col">Include</th><th scope="col">Job</th><th scope="col">Service date</th><th scope="col" class="is-num">Sharing base</th><th scope="col">Open issues</th></tr></thead>
                        <tbody>
                            @foreach ($candidates['jobs'] as $j)
                                <tr data-candidate="{{ $j['reference'] }}">
                                    <td>
                                        @if ($j['settled_by'])
                                            <span class="fin-status fin-status--blue">Settled in {{ $j['settled_by'] }}</span>
                                        @else
                                            <input type="checkbox" name="jobs[]" value="{{ $j['key'] }}" checked aria-label="Include job {{ $j['reference'] }}">
                                        @endif
                                    </td>
                                    <td><span class="fin-ref">{{ $j['reference'] }}</span></td>
                                    <td>{{ $j['service_date']?->format('D, M j') }}</td>
                                    <td class="is-num">{{ $j['base'] ? $j['base']->format() : 'Not allocated' }}</td>
                                    <td>
                                        @forelse ($j['blockers'] as $b)
                                            <div class="fin-flag">{{ $b['message'] }}</div>
                                        @empty
                                            <span class="fin-status fin-status--green">None</span>
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @foreach ($candidates['draft']['blockers'] as $b)
                    @if ($b['scope'] !== 'job')<div class="fin-inline-warning">{{ $b['message'] }}</div>@endif
                @endforeach
                <div class="fin-form-actions"><button type="submit" class="oa-btn oa-btn--primary" @disabled($open && $open->status !== 'draft')>Calculate draft statement</button></div>
            @endif
        </form>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/finance.js') }}?v={{ filemtime(public_path('superadmin/js/finance.js')) }}" defer></script>
@endpush
