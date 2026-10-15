@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator $paginator */
    $total = $paginator->total();
@endphp

<footer class="ob-footer">
    <p class="ob-count">Showing {{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }} of {{ number_format($total) }} {{ $total === 1 ? $noun : $noun . 's' }}</p>

    @if ($paginator->hasPages())
        @php
            $current = $paginator->currentPage();
            $last = $paginator->lastPage();
            $pages = collect([1, $current - 1, $current, $current + 1, $last])
                ->filter(fn ($p) => $p >= 1 && $p <= $last)
                ->unique()
                ->sort()
                ->values();
        @endphp
        <nav class="ob-pages" aria-label="Pagination">
            @if ($paginator->onFirstPage())
                <span class="ob-page-btn is-disabled" aria-hidden="true"><i data-lucide="chevron-left"></i></span>
            @else
                <a class="ob-page-btn" href="{{ $paginator->previousPageUrl() }}" aria-label="Previous page"><i data-lucide="chevron-left" aria-hidden="true"></i></a>
            @endif

            @foreach ($pages as $i => $page)
                @if ($i > 0 && $page - $pages[$i - 1] > 1)
                    <span class="ob-page-gap" aria-hidden="true">…</span>
                @endif
                @if ($page === $current)
                    <span class="ob-page-btn is-current" aria-current="page">{{ $page }}</span>
                @else
                    <a class="ob-page-btn" href="{{ $paginator->url($page) }}" aria-label="Page {{ $page }}">{{ $page }}</a>
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a class="ob-page-btn" href="{{ $paginator->nextPageUrl() }}" aria-label="Next page"><i data-lucide="chevron-right" aria-hidden="true"></i></a>
            @else
                <span class="ob-page-btn is-disabled" aria-hidden="true"><i data-lucide="chevron-right"></i></span>
            @endif
        </nav>
    @endif
</footer>
