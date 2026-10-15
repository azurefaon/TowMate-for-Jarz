{{-- Payment instructions (current invoices) and the authorised-signatory block carried over from the previous templates. --}}
<table class="doc-signoff">
    <tr>
        <td class="doc-signoff-pay">
            @if ($payment)
                <div class="doc-pay">
                    <strong>PAYMENT INSTRUCTIONS</strong><br>
                    @if (filled($c['bank_account_number']))
                        {{ $c['bank_account_name'] }} · {{ $c['bank_name'] }} Acc. # <strong>{{ $c['bank_account_number'] }}</strong><br>
                    @endif
                    @if (filled($c['gcash_number']))
                        GCash: <strong>{{ $c['gcash_number'] }}</strong>@if (filled($c['gcash_name'])) ({{ $c['gcash_name'] }})@endif<br>
                    @endif
                    @if (filled($c['payment_terms']))
                        {{ $c['payment_terms'] }}
                    @endif
                </div>
            @endif
        </td>
        <td class="doc-signoff-sig">
            @if (filled($c['signature_image']))
                <img src="{{ $c['signature_image'] }}" alt="Signature" class="doc-signature">
            @else
                <div class="doc-signature-blank"></div>
            @endif
            <div class="doc-signature-line">
                @if (filled($c['signatory']))<div class="doc-signature-name">{{ $c['signatory'] }}</div>@endif
                <div class="doc-signature-role">{{ $c['signatory_role'] }}</div>
            </div>
        </td>
    </tr>
</table>
