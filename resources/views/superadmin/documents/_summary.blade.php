{{-- Phone-width reading view: stored facts only, no derived figures. --}}
<div class="dv-summary">
    <dl class="dv-facts">
        @foreach ($facts as [$label, $value])
            <dt>{{ $label }}</dt>
            <dd>{{ $value }}</dd>
        @endforeach
    </dl>
    @if ($viewUrl)
        <a class="dv-btn is-primary" href="{{ $viewUrl }}" target="_blank" rel="noopener noreferrer">Open original PDF</a>
    @endif
</div>
