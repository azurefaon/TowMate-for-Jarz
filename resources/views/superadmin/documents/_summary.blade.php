{{-- Phone-width reading view of the document (stored figures only, nothing derived). Also the whole panel when the document itself is unavailable. --}}
<div class="dv-summary {{ ($always ?? false) ? 'is-always' : '' }}">
    <dl class="dv-facts">
        @foreach ($facts as [$label, $value])
            <dt>{{ $label }}</dt>
            <dd>{{ $value }}</dd>
        @endforeach
    </dl>
</div>
