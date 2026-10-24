@php($c = $doc['company'])
@include('documents.partials.pdf-frame', ['c' => $c])
@include('documents.partials.pdf-head', ['c' => $c, 'title' => 'RECEIPT', 'withLogo' => false])

<table class="pdf-meta">
    <tr>
        <td style="padding-right: 30pt">
            <div class="pdf-label">RECEIVED FROM</div>
            <div class="pdf-name">{{ $doc['customer']['name'] }}</div>
            <div class="pdf-contact">
                @if (filled($doc['customer']['phone'])){{ $doc['customer']['phone'] }}<br>@endif
                @if (filled($doc['customer']['email'])){{ $doc['customer']['email'] }}@endif
            </div>
        </td>
        <td style="width: 232pt">
            <table class="pdf-kv">
                <tr><td class="k">Receipt number</td><td class="v">{{ $doc['number'] }}</td></tr>
                <tr><td class="k">Receipt date</td><td class="v">{{ $doc['date'] }}</td></tr>
                <tr><td class="k">Booking reference</td><td class="v">{{ $doc['booking_ref'] }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="pdf-amount {{ $doc['panel_bg'] === '#FACC15' ? '' : 'muted' }}">
    <tr>
        <td class="al">{{ $doc['amount_label'] }}</td>
        <td class="av">{{ $doc['amount'] }}</td>
    </tr>
</table>

<table class="pdf-rows" style="border-top: 0; margin-top: 22pt">
    @foreach ($doc['pdf_rows'] as $row)
        <tr>
            <td class="k">{{ $row['label'] }}</td>
            <td class="v {{ $row['bold'] ? 'b' : '' }}">{{ $row['value'] }}</td>
        </tr>
    @endforeach
</table>
