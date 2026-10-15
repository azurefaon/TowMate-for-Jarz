@php($c = $doc['company'])
<div class="doc-sheet doc-invoice">
    <div class="doc-pad">
        <table class="doc-head">
            <tr>
                <td>
                    <table>
                        <tr>
                            <td style="width: 54pt; padding-right: 12pt">
                                @if (filled($c['logo']))
                                    <img src="{{ $c['logo'] }}" alt="{{ $c['name'] }} logo" class="doc-logo">
                                @endif
                            </td>
                            <td>
                                <div class="doc-company">{{ $c['name'] }}</div>
                                <div class="doc-company-sub">{{ $c['address'] }}<br>{{ $c['phone'] }} · {{ $c['email'] }}</div>
                            </td>
                        </tr>
                    </table>
                </td>
                <td class="doc-title-cell">
                    <div class="doc-title">INVOICE</div>
                    <div style="margin-top: 5pt"><span class="doc-badge" style="background: {{ $doc['status_bg'] }}">{{ $doc['status_label'] }}</span></div>
                </td>
            </tr>
        </table>

        <div class="doc-bar"></div>

        @if ($doc['note'])
            <div class="doc-note">{{ $doc['note'] }}</div>
        @endif

        <table class="doc-meta">
            <tr>
                <td style="padding-right: 36pt; padding-top: 5pt">
                    <div class="doc-label">BILLED TO</div>
                    <div class="doc-name">{{ $doc['customer']['name'] }}</div>
                    <div class="doc-contact">
                        @if (filled($doc['customer']['phone'])){{ $doc['customer']['phone'] }}<br>@endif
                        @if (filled($doc['customer']['email'])){{ $doc['customer']['email'] }}@endif
                    </div>
                </td>
                <td style="width: 225pt">
                    <table class="doc-kv">
                        <tr><td class="k">Invoice number</td><td class="v doc-mono">{{ $doc['number'] }}</td></tr>
                        <tr><td class="k">Invoice date</td><td class="v">{{ $doc['date'] }}</td></tr>
                        <tr><td class="k">Booking reference</td><td class="v doc-mono">{{ $doc['booking_ref'] }}</td></tr>
                        @if ($doc['replaces'])
                            <tr><td class="k">Replaces</td><td class="v doc-mono">{{ $doc['replaces'] }}</td></tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>

        <table class="doc-items">
            <thead>
                <tr>
                    <th class="l">DESCRIPTION</th>
                    <th style="width: 54pt">QTY</th>
                    <th style="width: 78pt">UNIT PRICE</th>
                    <th style="width: 87pt">AMOUNT</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($doc['lines'] as $line)
                    <tr>
                        <td class="l">
                            <div class="doc-item-title">{{ $line['title'] }}</div>
                            @if ($line['detail'])
                                <div class="doc-item-detail">{!! nl2br(e($line['detail'])) !!}</div>
                            @endif
                        </td>
                        <td>{{ $line['qty'] }}</td>
                        <td>{{ $line['unit'] }}</td>
                        <td style="font-weight: bold">{{ $line['amount'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($doc['breakdown_note'])
            <div class="doc-breakdown-note">{{ $doc['breakdown_note'] }}</div>
        @endif

        <table class="doc-totals">
            <tr>
                <td></td>
                <td style="width: 240pt">
                    <table class="doc-sum">
                        @foreach ($doc['rows'] as $row)
                            <tr><td>{{ $row['label'] }}</td><td class="n">{{ $row['value'] }}</td></tr>
                        @endforeach
                    </table>
                    <table class="doc-total" style="background: {{ $doc['total_bg'] }}">
                        <tr>
                            <td class="tl">TOTAL</td>
                            <td class="ta" style="color: {{ $doc['total_fg'] }}">{{ $doc['total'] }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        @include('documents.partials.signoff', ['c' => $c, 'payment' => $doc['show_payment']])
    </div>

    @include('documents.partials.doc-footer', ['c' => $c, 'noun' => 'invoice'])
</div>
