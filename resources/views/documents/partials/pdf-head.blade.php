{{-- Company header. The logo is shown on the quotation only. --}}
<table class="pdf-head">
    <tr>
        <td>
            <table>
                <tr>
                    @if (($withLogo ?? false) && filled($c['logo']))
                        <td style="width: 46pt; padding-right: 12pt"><img src="{{ $c['logo'] }}" alt="{{ $c['name'] }}" class="pdf-logo"></td>
                    @endif
                    <td>
                        <div class="pdf-company">{{ $c['name'] }}</div>
                        <div class="pdf-company-sub">{{ $c['address'] }}<br>{{ $c['phone'] }} · {{ $c['email'] }}</div>
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 190pt"><div class="pdf-title">{{ $title }}</div></td>
    </tr>
</table>
