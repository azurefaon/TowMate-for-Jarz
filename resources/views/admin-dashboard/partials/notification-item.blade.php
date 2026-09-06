@php
    $subtitleLines = $notification->subtitle_lines;
@endphp
<a href="{{ route('admin.notifications.open', $notification) }}" class="notif-item"
    data-unread="{{ $notification->isRead() ? 'false' : 'true' }}">
    <span class="notif-state" aria-hidden="true">{{ $notification->isRead() ? '✓' : '✕' }}</span>
    <span class="sr-only">{{ $notification->isRead() ? 'Read.' : 'Unread.' }}</span>
    <span class="notif-body">
        <span class="notif-top">
            <strong>{{ $notification->title }}</strong>
            <span class="notif-time">{{ $notification->created_at->diffForHumans() }}</span>
        </span>
        @foreach ($subtitleLines as $line)
            <span class="notif-line">{{ $line }}</span>
        @endforeach
    </span>
</a>
