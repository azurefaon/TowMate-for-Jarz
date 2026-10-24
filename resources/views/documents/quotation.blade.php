@php($doc = $doc ?? app(\App\Services\DocumentPresenter::class)->quotation($quotation, $booking ?? null))
@php($c = $doc['company'])
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Quotation {{ $doc['number'] }}</title>
    @include('documents.partials.doc-pdf-styles')
</head>

<body>
    @include('documents.partials.pdf-frame', ['c' => $c])
    @include('documents.partials.pdf-head', ['c' => $c, 'title' => 'QUOTATION', 'withLogo' => true])

    <table class="pdf-meta">
        <tr>
            <td style="padding-right: 30pt">
                <div class="pdf-label">PREPARED FOR</div>
                <div class="pdf-name">{{ $doc['customer']['name'] }}</div>
                <div class="pdf-contact">
                    @if (filled($doc['customer']['phone'])){{ $doc['customer']['phone'] }}<br>@endif
                    @if (filled($doc['customer']['email'])){{ $doc['customer']['email'] }}@endif
                </div>
            </td>
            <td style="width: 232pt">
                <table class="pdf-kv">
                    <tr><td class="k">Quotation number</td><td class="v">{{ $doc['number'] }}</td></tr>
                    <tr><td class="k">Quotation date</td><td class="v">{{ $doc['date'] }}</td></tr>
                    @if ($doc['valid_until'])
                        <tr><td class="k">Valid until</td><td class="v">{{ $doc['valid_until'] }}</td></tr>
                    @endif
                    @if ($doc['booking_ref'])
                        <tr><td class="k">Booking reference</td><td class="v">{{ $doc['booking_ref'] }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <div class="pdf-section">SERVICE</div>
    <table class="pdf-rows">
        @if (filled($doc['vehicle']))
            <tr><td class="k">Vehicle</td><td class="v b">{{ $doc['vehicle'] }}</td></tr>
        @endif
        <tr><td class="k">Pick-up</td><td class="v">{{ $doc['pickup'] ?: '—' }}</td></tr>
        <tr><td class="k">Drop-off</td><td class="v">{{ $doc['dropoff'] ?: '—' }}</td></tr>
    </table>

    <div style="page-break-inside: avoid">
    <table class="pdf-lines" style="margin-top: 18pt">
        <thead>
            <tr>
                <th class="l">CHARGES</th>
                <th style="width: 110pt">AMOUNT</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['charges'] as $charge)
                <tr>
                    <td class="l" style="font-size: 10.5pt; word-wrap: break-word">{{ $charge['label'] }}</td>
                    <td style="font-weight: bold">{{ $charge['amount'] }}</td>
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
                @if (count($doc['summary']))
                    <table class="pdf-sum">
                        @foreach ($doc['summary'] as $row)
                            <tr><td>{{ $row['label'] }}</td><td class="n">{{ $row['value'] }}</td></tr>
                        @endforeach
                    </table>
                @endif
                <table class="pdf-total">
                    <tr>
                        <td class="tl">ESTIMATED TOTAL<br>INCL. VAT</td>
                        <td class="ta">{{ $doc['total'] }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    </div>

    @if ($doc['terms'])
        <div class="pdf-terms"><strong>Terms:</strong> {{ $doc['terms'] }}</div>
    @endif
</body>

</html>
