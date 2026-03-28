@if ($paginator->hasPages())

    <div class="pagination-wrapper">

        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <span class="pagination-btn disabled">‹</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="pagination-btn">‹</a>
        @endif

        {{-- Pages --}}
        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="pagination-btn disabled">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pagination-btn active">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="pagination-btn">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="pagination-btn">›</a>
        @else
            <span class="pagination-btn disabled">›</span>
        @endif

    </div>

@endif
