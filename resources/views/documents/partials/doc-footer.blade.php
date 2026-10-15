<div class="doc-footer">
    <table style="width: 100%">
        <tr>
            @if (filled($c['accreditation_logo']))
                <td style="width: 44pt; vertical-align: bottom; padding-right: 9pt">
                    <img src="{{ $c['accreditation_logo'] }}" alt="Accreditation" class="doc-accreditation">
                </td>
            @endif
            <td style="vertical-align: bottom">
                <div class="f1">Thank you for choosing {{ $c['name'] }}.</div>
                <div class="f2">Questions about this {{ $noun }}? {{ $c['phone'] }} · {{ $c['email'] }}</div>
            </td>
        </tr>
    </table>
</div>
