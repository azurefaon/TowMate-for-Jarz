{{-- Main panel of the Owner viewers: the modern document, or a simple unavailable state when the stored records cannot support it. --}}
@if ($available)
    <div class="dv-paper">
        @include('documents.partials.' . $noun . '-sheet', ['doc' => $doc])
    </div>
@else
    <div class="dv-unavailable" role="note">
        <h2>{{ ucfirst($noun) }} details unavailable</h2>
        <p>{{ $unavailable }}</p>
    </div>
@endif

@include('superadmin.documents._summary', ['facts' => $facts, 'always' => ! $available])

{{-- With an official PDF the PDF actions carry the official copy; without one this view is reference only. --}}
@if ($official)
    <p class="dv-print-note">To print this document, use Print PDF.</p>
@else
    <p class="dv-note">For reference only. Shown from the booking's stored records.</p>
    <p class="dv-print-note">This view is for reference only and is not printed.</p>
@endif
