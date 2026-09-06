@extends('admin-dashboard.layouts.app')

@section('title', 'Notifications')

@push('styles')
    <link rel="stylesheet" href="{{ asset('dispatcher/css/dashboard.css') }}">
@endpush

@section('content')
    <div class="notifications-page">
        <div class="notif-page-header">
            <h1>Notifications</h1>
        </div>

        <div class="notif-page-list">
            @forelse ($notifications as $notification)
                @include('admin-dashboard.partials.notification-item', ['notification' => $notification])
            @empty
                <div class="notif-empty">No notifications yet.</div>
            @endforelse
        </div>

        <div class="notif-page-pagination">
            {{ $notifications->links() }}
        </div>
    </div>
@endsection
