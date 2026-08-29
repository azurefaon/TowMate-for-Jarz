{{-- Dedicated pagination view for the Users / Archived Users pages: a fixed 7-number
     sliding window (page 1 shows 1-7, page 2 shows 2-8, page 3 shows 3-9, ...) plus
     First/Last jump buttons. Scoped to these pages only — the shared
     vendor/pagination/custom.blade.php (used by Vehicle Types, Unit-Truck, Truck Types,
     Activity Reports, etc.) is untouched. --}}
@if ($paginator->hasPages() || $paginator->total() >= 5)
    @php
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();
        $windowSize = 7;
        $windowEnd = min($last, $current + $windowSize - 1);
        $windowStart = max(1, $windowEnd - $windowSize + 1);
    @endphp

    <div class="pagination-wrapper">

        {{-- First --}}
        @if ($current <= 1)
            <span class="pagination-btn disabled">«</span>
        @else
            <a href="{{ $paginator->url(1) }}" class="pagination-btn">«</a>
        @endif

        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <span class="pagination-btn disabled">‹</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="pagination-btn">‹</a>
        @endif

        {{-- Sliding window of page numbers --}}
        @for ($page = $windowStart; $page <= $windowEnd; $page++)
            @if ($page == $current)
                <span class="pagination-btn active">{{ $page }}</span>
            @else
                <a href="{{ $paginator->url($page) }}" class="pagination-btn">{{ $page }}</a>
            @endif
        @endfor

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="pagination-btn">›</a>
        @else
            <span class="pagination-btn disabled">›</span>
        @endif

        {{-- Last --}}
        @if ($current >= $last)
            <span class="pagination-btn disabled">»</span>
        @else
            <a href="{{ $paginator->url($last) }}" class="pagination-btn">»</a>
        @endif

    </div>
@endif
