@extends('layouts.superadmin')

@section('title', 'Finance Demo')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/finance.css') }}?v={{ filemtime(public_path('superadmin/css/finance.css')) }}">
@endpush

@php
    $w = ['from' => $week->toDateString(), 'to' => $week->addDays(5)->toDateString()];
    $steps = [
        ['Overview', route('superadmin.finance.overview', $w), 'Actual collections, expenses, unit funds, draft and approved commission kept separate.'],
        ['Jobs & Collections', route('superadmin.finance.collections.index', $w), 'Partial payment (₱7,500 of ₱15,000), the two-unit job pending allocation review, and the three-vehicle booking counted once.'],
        ['Unit Funds', route('superadmin.finance.unit-funds.index', $w), 'Unit 1: ₱3,000 issued, ₱2,950 spent, ₱50 returned, ₱0 remaining.'],
        ['Expenses', route('superadmin.finance.expenses.index', $w), 'Approved receipts, the ₱2,000 personal advance, and one Team Leader expense waiting for the Dispatcher.'],
        ['Job Crew', route('superadmin.finance.crew.index', ['week' => $w['from']]), 'Who actually worked each job; Unit 2 has no Rider.'],
        ['Weekly Commission', route('superadmin.finance.commission.index', ['week' => $w['from']]), 'Draft calculation and every blocker.'],
        ['Commission Statements', route('superadmin.finance.statements.index'), 'Unit 1 approved (base ₱12,050 → ₱7,230 / ₱4,820) with two simulated payouts; Unit 2 For Review and blocked.'],
        ['Payout records', route('superadmin.finance.statements.index', ['status' => 'approved']), 'Record the remaining simulated payouts, then Mark statement Paid. No money moves.'],
        ['Finance Reports', route('superadmin.finance.reports.index', $w), 'Same totals on screen, in print / PDF and in Excel.'],
        ['Client Review', route('superadmin.finance.client-review.index'), 'Pending decisions, including the review-only 50:50 proposal and the personal advance.'],
    ];
@endphp

@section('content')
    <div class="ob-page oa-page fin-page" id="ownerFinanceDemo">
        <header class="ob-header fin-header">
            <div>
                <h1>Finance Demo</h1>
                <p>Demonstration database "{{ $database }}". Every record here is sample data built through the real Finance services. Week of {{ $week->format('M j') }} – {{ $week->addDays(5)->format('M j, Y') }}.</p>
            </div>
        </header>

        @include('superadmin.finance._flashes')

        <section class="oa-card oa-card-body" aria-labelledby="dmSteps">
            <div class="oa-card-head"><div><h2 class="oa-card-title" id="dmSteps"><i data-lucide="list-ordered" aria-hidden="true"></i> Demonstration walkthrough</h2></div></div>
            <ol class="fin-trail" style="list-style:decimal; padding-left:20px;">
                @foreach ($steps as [$label, $url, $note])
                    <li style="display:list-item"><a href="{{ $url }}" data-demo-step="{{ $label }}"><strong>{{ $label }}</strong></a> — {{ $note }}</li>
                @endforeach
            </ol>
        </section>

        <section class="oa-card oa-card-body" aria-labelledby="dmScen">
            <div class="oa-card-head"><div><h2 class="oa-card-title" id="dmScen"><i data-lucide="flask-conical" aria-hidden="true"></i> Scenarios</h2></div></div>
            @if ($rows->isEmpty())
                <div class="oa-empty">No demo scenarios yet. Run <code>php artisan finance:demo-reset --env=demo</code> or use Reset below.</div>
            @else
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table">
                        <thead><tr><th scope="col">Unit</th><th scope="col">Bookings</th><th scope="col">Statements</th></tr></thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr data-demo-unit="{{ $row['unit']->name }}">
                                    <td class="fin-strong">{{ $row['unit']->name }}</td>
                                    <td>@foreach ($row['bookings'] as $b)<a href="{{ route('superadmin.finance.crew.show', $b->id) }}">{{ $b->booking_code }}</a>{{ $b->group_code ? " (group {$b->group_code})" : '' }}{{ $loop->last ? '' : ', ' }}@endforeach</td>
                                    <td>@forelse ($row['statements'] as $s)<a href="{{ route('superadmin.finance.statements.show', $s->id) }}">{{ $s->reference_code }}</a> ({{ $s->statusLabel() }}){{ $loop->last ? '' : ', ' }}@empty — @endforelse</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="oa-card oa-card-body" aria-labelledby="dmReset">
            <div class="oa-card-head"><div>
                <h2 class="oa-card-title" id="dmReset"><i data-lucide="rotate-ccw" aria-hidden="true"></i> Reset the demonstration</h2>
                <p class="oa-card-sub">Drops and rebuilds every table in this demo database and recreates the six scenarios. You will be signed out. This control exists only on a demo database and cannot run anywhere else.</p>
            </div></div>
            <form method="POST" action="{{ route('superadmin.finance.demo.reset') }}" class="fin-inline-form" data-fin-once>
                @csrf
                <input type="text" name="confirm" required placeholder="Type RESET DEMO" aria-label="Type RESET DEMO to confirm">
                <button type="submit" class="oa-btn oa-btn--sm fin-btn-danger">Reset demo data</button>
            </form>
        </section>
    </div>
@endsection
