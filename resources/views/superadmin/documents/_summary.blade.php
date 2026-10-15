{{-- Phone-width reading view: stored facts only, no derived figures. The Original PDF actions live once, in the side panel below. --}}
<div class="dv-summary">
    <dl class="dv-facts">
        @foreach ($facts as [$label, $value])
            <dt>{{ $label }}</dt>
            <dd>{{ $value }}</dd>
        @endforeach
    </dl>
</div>
