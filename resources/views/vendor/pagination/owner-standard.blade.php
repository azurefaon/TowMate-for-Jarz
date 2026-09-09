<div class="owner-pagination">
    <span class="owner-pagination-summary">
        @if ($paginator->total() > 0)
            Showing {{ $paginator->firstItem() }}&ndash;{{ $paginator->lastItem() }} of {{ $paginator->total() }} {{ $paginator->total() === 1 ? 'record' : 'records' }}
        @else
            Showing 0 records
        @endif
    </span>

    @if ($paginator->hasPages())
        <nav aria-label="Pagination">
            <ul class="owner-pagination-controls">
                <li>
                    @if ($paginator->onFirstPage())
                        <span class="owner-pagination-btn is-disabled" aria-disabled="true">&lsaquo; Previous</span>
                    @else
                        <a href="{{ $paginator->previousPageUrl() }}" class="owner-pagination-btn" rel="prev">&lsaquo; Previous</a>
                    @endif
                </li>

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <li><span class="owner-pagination-ellipsis">{{ $element }}</span></li>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            <li>
                                @if ($page == $paginator->currentPage())
                                    <span class="owner-pagination-btn is-active" aria-current="page">{{ $page }}</span>
                                @else
                                    <a href="{{ $url }}" class="owner-pagination-btn">{{ $page }}</a>
                                @endif
                            </li>
                        @endforeach
                    @endif
                @endforeach

                <li>
                    @if ($paginator->hasMorePages())
                        <a href="{{ $paginator->nextPageUrl() }}" class="owner-pagination-btn" rel="next">Next &rsaquo;</a>
                    @else
                        <span class="owner-pagination-btn is-disabled" aria-disabled="true">Next &rsaquo;</span>
                    @endif
                </li>
            </ul>
        </nav>
    @endif
</div>
