@php($c = $doc['company'])
@include('documents.partials.pdf-frame', ['c' => $c])
@include('documents.partials.pdf-head', ['c' => $c, 'title' => 'INVOICE', 'withLogo' => false])

<table class="pdf-meta">
    <tr>
        <td style="padding-right: 30pt">
            <div class="pdf-label">BILLED TO</div>
            <div class="pdf-name">{{ $doc['customer']['name'] }}</div>
            <div class="pdf-contact">
                @if (filled($doc['customer']['phone'])){{ $doc['customer']['phone'] }}<br>@endif
                @if (filled($doc['customer']['email'])){{ $doc['customer']['email'] }}@endif
            </div>
        </td>
        <td style="width: 232pt">
            <table class="pdf-kv">
                <tr><td class="k">Invoice number</td><td class="v">{{ $doc['number'] }}</td></tr>
                <tr><td class="k">Invoice date</td><td class="v">{{ $doc['date'] }}</td></tr>
                <tr><td class="k">Booking reference</td><td class="v">{{ $doc['booking_ref'] }}</td></tr>
                @if ($doc['replaces'])
                    <tr><td class="k">Replaces</td><td class="v">{{ $doc['replaces'] }}</td></tr>
                @endif
                <tr><td class="k">Status</td><td class="v">{{ $doc['pdf_status'] }}</td></tr>
            </table>
        </td>
    </tr>
</table>

@if ($doc['note'])
    <div class="pdf-note"><strong>{{ $doc['note'] }}</strong></div>
@endif

@if ($doc['is_group'] && $doc['reliable'])
    <div class="pdf-route"><strong>Route for all vehicles:</strong> {{ $doc['route_text'] }}</div>
@endif

<table class="pdf-lines" @if (! ($doc['is_group'] && $doc['reliable'])) style="margin-top: 26pt" @endif>
    <thead>
        <tr>
            <th class="l">DESCRIPTION</th>
            <th style="width: 44pt">QTY</th>
            <th style="width: 84pt">UNIT PRICE</th>
            <th style="width: 90pt">AMOUNT</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($doc['lines'] as $i => $line)
            @php($single = ! $doc['is_group'] && $i === 0)
            <tr>
                <td class="l">
                    <div class="pdf-item-title">{{ $line['title'] }}@if ($single && filled($doc['vehicle'])) — {{ $doc['vehicle'] }}@endif</div>
                    @if ($single)
                        <div class="pdf-item-detail">Route: {{ $doc['route_text'] }}</div>
                    @elseif ($line['detail'] && ! $doc['is_group'])
                        <div class="pdf-item-detail">{!! nl2br(e($line['detail'])) !!}</div>
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
    <div class="pdf-note">{{ $doc['breakdown_note'] }}</div>
@endif

<table class="pdf-totals">
    <tr>
        <td></td>
        <td style="width: 232pt">
            @if (count($doc['rows']) > 1 || (count($doc['rows']) === 1 && $doc['rows'][0]['label'] !== 'Subtotal'))
                <table class="pdf-sum">
                    @foreach ($doc['rows'] as $row)
                        <tr><td>{{ $row['label'] }}</td><td class="n">{{ $row['value'] }}</td></tr>
                    @endforeach
                </table>
            @endif
            <table class="pdf-total {{ in_array($doc['state'], ['replaced', 'voided'], true) ? 'muted' : '' }}">
                <tr>
                    <td class="tl">TOTAL</td>
                    <td class="ta">{{ $doc['total'] }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>
