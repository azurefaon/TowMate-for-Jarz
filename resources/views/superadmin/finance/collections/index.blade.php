@extends('layouts.superadmin')

@section('title', 'Jobs & Collections')

@push('styles')
    <link rel="stylesheet" href="{{ asset('superadmin/css/bookings.css') }}?v={{ filemtime(public_path('superadmin/css/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/analytics.css') }}?v={{ filemtime(public_path('superadmin/css/analytics.css')) }}">
    <link rel="stylesheet" href="{{ asset('superadmin/css/finance.css') }}?v={{ filemtime(public_path('superadmin/css/finance.css')) }}">
@endpush

@php
    $openForm = old('form');
    $statusTone = ['paid' => 'green', 'partial' => 'amber', 'awaiting_verification' => 'blue', 'completed_unverified' => 'red', 'unpaid' => 'red', 'overpaid' => 'amber'];
    $allocationTone = ['automatic' => 'green', 'manual' => 'green', 'pending_review' => 'amber', 'nothing_collected' => 'grey'];
    $fmtDate = fn ($d) => $d ? $d->format('M j, Y') : '—';
@endphp

@section('content')
    <div class="ob-page oa-page fin-page" id="ownerCollections">

        <header class="ob-header fin-header">
            <div>
                <h1>Jobs &amp; Collections</h1>
                <p>Completed towing jobs, what the customer agreed to pay, what was actually collected, and which unit earned it.</p>
            </div>
        </header>

        @include('superadmin.finance._flashes')

        <form method="GET" action="{{ route('superadmin.finance.collections.index') }}" class="oa-card oa-filter-card fin-filters" role="search" id="jcFilters" data-fin-live data-fin-target="#jcResults">
            <div class="oa-filters">
                <div class="oa-field">
                    <label for="jcUnit">Unit</label>
                    <select id="jcUnit" name="unit_id" class="oa-select">
                        <option value="">All units</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}" @selected($filters['unit_id'] === $unit->id)>{{ $unit->name }}{{ $unit->archived_at ? ' (archived)' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <label for="jcBooking">Booking</label>
                    <input id="jcBooking" type="search" name="booking" class="fin-search" value="{{ $filters['booking'] }}" placeholder="Booking or group code, e.g. TM-00148"
                           autocomplete="off" spellcheck="false" maxlength="40" aria-describedby="jcLiveStatus" data-fin-live-search>
                </div>
                <div class="oa-field">
                    <label for="jcStatus">Collection status</label>
                    <select id="jcStatus" name="status" class="oa-select">
                        <option value="">All</option>
                        <option value="outstanding" @selected($filters['status'] === 'outstanding')>Has an outstanding balance</option>
                        @foreach (\App\Services\Finance\JobCollectionService::COLLECTION_STATUSES as $key => $label)
                            <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <label for="jcMethod">Payment method</label>
                    <select id="jcMethod" name="method" class="oa-select">
                        <option value="">Any</option>
                        @foreach (\App\Models\Booking::PREFERRED_PAYMENT_METHODS as $key => $label)
                            <option value="{{ $key }}" @selected($filters['method'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <label for="jcAllocation">Unit allocation</label>
                    <select id="jcAllocation" name="allocation" class="oa-select">
                        <option value="">Any</option>
                        @foreach (\App\Services\Finance\JobCollectionService::ALLOCATION_STATUSES as $key => $label)
                            <option value="{{ $key }}" @selected($filters['allocation'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="oa-field">
                    <label for="jcBasis">Date by</label>
                    <select id="jcBasis" name="basis" class="oa-select">
                        <option value="service" @selected($filters['basis'] === 'service')>Service date</option>
                        <option value="payment" @selected($filters['basis'] === 'payment')>Payment date</option>
                    </select>
                </div>
                <div class="oa-field">
                    <span class="oa-field-label">Period</span>
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        @include('superadmin.analytics._range', ['label' => $periodLabel, 'from' => $from->toDateString(), 'to' => $to->toDateString()])
                        <noscript><button type="submit" class="oa-btn oa-btn--primary">Apply</button></noscript>
                        <a href="{{ route('superadmin.finance.collections.index') }}" class="oa-btn">This week</a>
                    </div>
                </div>
            </div>
            <p class="fin-live-status" id="jcLiveStatus" role="status" aria-live="polite" data-fin-live-status></p>
        </form>

        <div id="jcResults" class="fin-live-region" aria-busy="false">

        <div class="oa-kpis oa-kpis--six" style="--oa-kpi-count: 6;">
            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="clipboard-check" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Bookings</span>
                <div class="oa-kpi-value" data-jc-total="bookings">{{ number_format($totals['bookings']) }}</div>
                <span class="fin-kpi-sub">{{ $totals['completed_bookings'] }} with service completed</span>
            </div>
            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="car" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Vehicles towed</span>
                <div class="oa-kpi-value" data-jc-total="vehicles">{{ number_format($totals['vehicles']) }}</div>
            </div>
            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="file-text" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Agreed charges</span>
                <div class="oa-kpi-value" data-jc-total="agreed">{{ $totals['agreed']->format() }}</div>
            </div>
            <div class="oa-kpi is-dark">
                <span class="oa-kpi-icon"><i data-lucide="banknote" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Verified collections</span>
                <div class="oa-kpi-value" data-jc-total="collected">{{ $totals['collected']->format() }}</div>
            </div>
            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="hourglass" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Outstanding balance</span>
                <div class="oa-kpi-value" data-jc-total="outstanding">{{ $totals['outstanding']->format() }}</div>
                @if ($totals['over_collected']->isPositive())
                    <span class="fin-kpi-sub">Over-collected: {{ $totals['over_collected']->format() }}</span>
                @endif
            </div>
            <div class="oa-kpi">
                <span class="oa-kpi-icon"><i data-lucide="split" aria-hidden="true"></i></span>
                <span class="oa-kpi-label">Allocations pending review</span>
                <div class="oa-kpi-value" data-jc-total="pending">{{ number_format($totals['pending_allocations']) }}</div>
            </div>
        </div>

        <p class="oa-note">
            <i data-lucide="info" aria-hidden="true"></i>
            <span>
                <strong>Verified collections</strong> count only payments a Dispatcher confirmed, plus live online payments verified by the payment provider.
                The customer's chosen method, a payment still awaiting verification, PayMongo TEST payments, demo bookings and change handed back to the customer are never counted.
                A job marked completed without a payment confirmation is shown as not verified.
                One booking can cover several vehicles; group trips are counted once. Dates are Philippine time, and the default period is this Monday–Saturday week.
                Refunds and payment-provider fees are not recorded in TowMate yet.
            </span>
        </p>

        <section class="oa-card oa-card-body" aria-labelledby="jcUnitTitle">
            <div class="oa-card-head">
                <div>
                    <h2 class="oa-card-title" id="jcUnitTitle"><i data-lucide="truck" aria-hidden="true"></i> Revenue by unit</h2>
                    <p class="oa-card-sub">Allocated verified collections for {{ $periodLabel }}. Jobs pending review are not included until they are allocated.</p>
                </div>
            </div>
            @if ($totals['by_unit']->isNotEmpty())
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table" id="jcByUnit">
                        <thead><tr><th scope="col">Unit</th><th scope="col" class="is-num">Jobs</th><th scope="col" class="is-num">Vehicles</th><th scope="col" class="is-num">Allocated revenue</th></tr></thead>
                        <tbody>
                            @foreach ($totals['by_unit'] as $row)
                                <tr data-jc-unit="{{ $row['unit_id'] }}">
                                    <td>{{ $row['unit_name'] }}</td>
                                    <td class="is-num">{{ $row['jobs'] }}</td>
                                    <td class="is-num">{{ $row['vehicles'] }}</td>
                                    <td class="is-num fin-strong">{{ $row['amount']->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot><tr><th scope="row">Total allocated</th><td></td><td></td><td class="is-num fin-strong">{{ $totals['allocated']->format() }}</td></tr></tfoot>
                    </table>
                </div>
            @else
                <div class="oa-empty">No allocated revenue for this period.</div>
            @endif
        </section>

        <section class="oa-card oa-card-body" aria-labelledby="jcJobsTitle">
            <div class="oa-card-head">
                <div>
                    <h2 class="oa-card-title" id="jcJobsTitle"><i data-lucide="list" aria-hidden="true"></i> Jobs</h2>
                    <p class="oa-card-sub">{{ $periodLabel }}, by {{ $filters['basis'] === 'payment' ? 'payment' : 'service' }} date.</p>
                </div>
            </div>

            @if ($jobs->isNotEmpty())
                <div class="oa-table-wrap">
                    <table class="oa-table fin-table" id="jcTable">
                        <thead>
                            <tr>
                                <th scope="col">Booking</th>
                                <th scope="col">Customer</th>
                                <th scope="col" class="is-num">Vehicles</th>
                                <th scope="col" class="is-num">Agreed</th>
                                <th scope="col" class="is-num">Collected</th>
                                <th scope="col" class="is-num">Outstanding</th>
                                <th scope="col">Payment</th>
                                <th scope="col">Units</th>
                                <th scope="col"><span class="fin-sr">Details</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($jobs as $job)
                                @php $formKey = 'split-' . $job['anchor']->id; @endphp
                                <tr data-jc-job="{{ $job['anchor']->id }}">
                                    <td>
                                        <span class="fin-ref">{{ $job['reference'] }}</span>
                                        @if ($job['group_code'])<div class="is-dim">Group {{ $job['group_code'] }}</div>@endif
                                        <div class="is-dim">Service {{ $fmtDate($job['service_date']) }}</div>
                                    </td>
                                    <td>
                                        {{ $job['customer']?->full_name ?? '—' }}
                                        <div class="is-dim">{{ collect($job['units'])->pluck('truck_type')->filter()->unique()->implode(', ') }}</div>
                                    </td>
                                    <td class="is-num" data-jc-vehicles>{{ $job['vehicles'] }}</td>
                                    <td class="is-num">{{ $job['agreed']->format() }}</td>
                                    <td class="is-num fin-strong" data-jc-collected>{{ $job['collected']->format() }}</td>
                                    <td class="is-num {{ $job['outstanding']->isPositive() ? 'fin-negative' : '' }}" data-jc-outstanding>{{ $job['outstanding']->format() }}</td>
                                    <td>
                                        <span class="fin-status fin-status--{{ $statusTone[$job['status']] ?? 'grey' }}" data-jc-status="{{ $job['status'] }}">{{ $job['status_label'] }}</span>
                                        <div class="is-dim">{{ $job['method_label'] ?? 'No payment recorded' }}{{ $job['payment_date'] ? ' · ' . $fmtDate($job['payment_date']) : '' }}</div>
                                    </td>
                                    <td>
                                        @foreach (collect($job['units'])->unique('unit_id') as $u)
                                            <div>{{ $u['unit_name'] ?? 'No unit' }}</div>
                                        @endforeach
                                        <span class="fin-status fin-status--{{ $allocationTone[$job['allocation']['status']] ?? 'grey' }}">{{ $job['allocation']['status_label'] }}</span>
                                    </td>
                                    <td class="fin-actions">
                                        <details class="fin-details" @if ($openForm === $formKey) open @endif>
                                            <summary class="oa-btn oa-btn--sm">Details</summary>
                                            <div class="fin-details-body">
                                                @foreach ($job['flags'] as $key => $label)
                                                    <div class="fin-flag fin-flag--{{ $key }}">{{ $label }}</div>
                                                @endforeach

                                                <h3 class="fin-subhead">Charge and payment</h3>
                                                <ul class="fin-trail">
                                                    <li>Agreed charge {{ $job['agreed']->format() }} ({{ ['invoice' => 'current invoice ' . ($job['invoice']?->invoice_number ?? ''), 'group_formula' => 'group total of the vehicles plus group fee or discount', 'booking' => 'booking total'][$job['agreed_source']] }})</li>
                                                    <li>Payment confirmed by Dispatcher: {{ $job['confirmed_by_dispatcher'] ? 'Yes' : 'No' }}</li>
                                                    @if ($job['preferred_method_label'])<li>Customer's preferred method: {{ $job['preferred_method_label'] }} (a preference, not a payment)</li>@endif
                                                    @if ($job['cash_tendered'])<li>Cash tendered {{ $job['cash_tendered']->format() }}{{ $job['change']->isPositive() ? ', change ' . $job['change']->format() : '' }}</li>@endif
                                                    @foreach ($job['transactions'] as $t)
                                                        <li>Online payment {{ $t->reference }} · {{ \App\Support\Money::fromCentavos((int) $t->amount_centavos)->format() }} · {{ $t->status }}{{ $t->livemode ? '' : ' · TEST (not counted)' }}{{ $t->paid_at ? ' · ' . $t->paid_at->format('M j, Y g:i A') : '' }}</li>
                                                    @endforeach
                                                </ul>

                                                <h3 class="fin-subhead">Vehicles and units</h3>
                                                <ul class="fin-trail">
                                                    @foreach ($job['units'] as $u)
                                                        <li>{{ $u['booking_code'] }} · {{ $u['unit_name'] ?? 'No unit assigned' }}{{ $u['plate'] ? ' (' . $u['plate'] . ')' : '' }}</li>
                                                    @endforeach
                                                </ul>

                                                <h3 class="fin-subhead">Personnel at the time</h3>
                                                @if ($job['personnel']['people'])
                                                    <ul class="fin-trail">
                                                        @foreach ($job['personnel']['people'] as $p)
                                                            <li>{{ $p['name'] }} · {{ ucfirst($p['slot']) }}{{ $p['needs_review'] ? ' · needs review' : '' }}</li>
                                                        @endforeach
                                                    </ul>
                                                    @if ($job['personnel']['source'] === 'booking')
                                                        <p class="fin-help">From the booking's own assignment. Full crew capture comes in the job crew phase.</p>
                                                    @endif
                                                @else
                                                    <p class="fin-help">No crew was recorded for this job. A unit's current crew is never used for past jobs.</p>
                                                @endif

                                                <h3 class="fin-subhead">Expenses for this job</h3>
                                                <p class="fin-help">Approved {{ $job['expenses']['approved']->format() }} · awaiting review {{ $job['expenses']['pending']->format() }} (kept separate from collections)</p>

                                                <h3 class="fin-subhead">Unit revenue allocation</h3>
                                                @if ($job['allocation']['lines'])
                                                    <ul class="fin-trail">
                                                        @foreach ($job['allocation']['lines'] as $line)
                                                            <li>{{ $line['unit_name'] }} · {{ $line['amount']->format() }}{{ count($job['members']) > 1 ? ' (' . $line['booking_code'] . ')' : '' }}</li>
                                                        @endforeach
                                                        <li class="fin-strong">Total {{ $job['allocation']['total']->format() }} of {{ $job['allocatable']->format() }} to allocate</li>
                                                    </ul>
                                                @elseif ($job['allocation']['reason'])
                                                    <p class="fin-inline-warning">Pending review: {{ $job['allocation']['reason'] }}.</p>
                                                @else
                                                    <p class="fin-help">Nothing collected yet.</p>
                                                @endif

                                                @if (count($job['members']) === 1 && $job['allocatable']->isPositive())
                                                    <details class="fin-correct" @if ($openForm === $formKey) open @endif>
                                                        <summary>Split between units</summary>
                                                        <form method="POST" action="{{ route('superadmin.finance.collections.split', $job['anchor']->id) }}" class="fin-form" data-fin-once>
                                                            @csrf
                                                            <p class="fin-help">Enter each unit's share. The amounts must add up to exactly {{ $job['allocatable']->format() }}. Nothing is split automatically.</p>
                                                            <div class="fin-grid fin-grid--compact">
                                                                @foreach ($activeUnits as $unit)
                                                                    @php $current = collect($job['allocation']['lines'])->firstWhere('unit_id', $unit->id); @endphp
                                                                    <label class="fin-field">
                                                                        <span>{{ $unit->name }} (₱)</span>
                                                                        <input type="text" name="amounts[{{ $unit->id }}]" inputmode="decimal" placeholder="0.00"
                                                                            value="{{ $openForm === $formKey ? old('amounts.' . $unit->id) : ($current && $job['allocation']['status'] === 'manual' ? $current['amount']->toString() : '') }}">
                                                                    </label>
                                                                @endforeach
                                                                <label class="fin-field fin-field--wide"><span>Note</span><input type="text" name="note" maxlength="1000" value="{{ $openForm === $formKey ? old('note') : '' }}" placeholder="e.g. Unit 2 assisted with the winching"></label>
                                                            </div>
                                                            <div class="fin-form-actions"><button type="submit" class="oa-btn oa-btn--primary oa-btn--sm">Save split</button></div>
                                                        </form>
                                                    </details>
                                                @endif
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="fin-pager">{{ $jobs->links() }}</div>
            @else
                <div class="oa-empty" data-jc-empty>
                    No bookings match these filters.
                    @if ($filters['booking']) No booking or group code contains “{{ $filters['booking'] }}”. @endif
                    Clear the search or change a filter to see more.
                </div>
            @endif
        </section>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('superadmin/js/analytics.js') }}?v={{ filemtime(public_path('superadmin/js/analytics.js')) }}" defer></script>
    <script src="{{ asset('superadmin/js/finance.js') }}?v={{ filemtime(public_path('superadmin/js/finance.js')) }}" defer></script>
    <script src="{{ asset('superadmin/js/finance-live-filters.js') }}?v={{ filemtime(public_path('superadmin/js/finance-live-filters.js')) }}" defer></script>
@endpush
