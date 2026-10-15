@php($c = $doc['company'])
<div class="doc-sheet doc-receipt">
    <div class="doc-topbar"></div>
    <div class="doc-pad" style="padding-top: 30pt">
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
                    <div class="doc-title">PAYMENT RECEIPT</div>
                </td>
            </tr>
        </table>

        <table class="doc-meta">
            <tr>
                <td style="padding-right: 36pt; padding-top: 5pt">
                    <div class="doc-label">RECEIVED FROM</div>
                    <div class="doc-name">{{ $doc['customer']['name'] }}</div>
                    <div class="doc-contact">
                        @if (filled($doc['customer']['phone'])){{ $doc['customer']['phone'] }}<br>@endif
                        @if (filled($doc['customer']['email'])){{ $doc['customer']['email'] }}@endif
                    </div>
                </td>
                <td style="width: 225pt">
                    <table class="doc-kv">
                        <tr><td class="k">Receipt number</td><td class="v doc-mono">{{ $doc['number'] }}</td></tr>
                        <tr><td class="k">Receipt date</td><td class="v">{{ $doc['date'] }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <div class="doc-amount" style="background: {{ $doc['panel_bg'] }}">
            <div class="al">{{ $doc['amount_label'] }}</div>
            <div class="av">{{ $doc['amount'] }}</div>
        </div>

        <table class="doc-rows">
            <thead>
                <tr><th colspan="2">PAYMENT DETAILS</th></tr>
            </thead>
            <tbody>
                @foreach ($doc['rows'] as $row)
                    <tr>
                        <td class="k">{{ $row['label'] }}</td>
                        <td class="v {{ $row['mono'] ? 'doc-mono' : '' }} {{ $row['muted'] ? 'muted' : '' }}">{{ $row['value'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @include('documents.partials.signoff', ['c' => $c, 'payment' => false])
    </div>

    @include('documents.partials.doc-footer', ['c' => $c, 'noun' => 'receipt'])
</div>
