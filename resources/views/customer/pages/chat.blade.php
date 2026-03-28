@extends('customer.layouts.app')

@section('title', 'Chat')

@section('content')

    <link rel="stylesheet" href="{{ asset('customer/css/chat.css') }}">

    <div class="chat-system">

        <!-- LEFT -->
        <div class="chat-list">

            <div class="chat-list-header">
                <h3>Your Conversations</h3>
            </div>

            @forelse ($chats ?? [] as $chat)
                <a href="{{ route('customer.chat.show', $chat->id) }}" class="chat-item">

                    <div class="chat-avatar">
                        {{ strtoupper(substr($chat->driver->name ?? 'D', 0, 1)) }}
                    </div>

                    <div class="chat-info">
                        <strong>{{ $chat->driver->name ?? 'Driver' }}</strong>
                        <p>{{ $chat->last_message ?? 'Start conversation...' }}</p>
                    </div>

                </a>
            @empty
                <div class="no-chat-box">

                    <div class="no-chat-icon">📭</div>

                    <p>No conversations yet</p>
                    <small>Your chats with drivers will appear here</small>

                </div>
            @endforelse

        </div>

        <!-- RIGHT -->
        <div class="chat-window">

            @if (isset($activeChat))

                <div class="chat-header">
                    <strong>{{ $activeChat->driver->name }}</strong>
                    <span class="online">● Online</span>
                </div>

                <div class="chat-messages" id="chatMessages">
                    @foreach ($activeChat->messages as $msg)
                        <div class="message {{ $msg->sender_id == auth()->id() ? 'user' : 'driver' }}">
                            <p>{{ $msg->message }}</p>
                            <span>{{ $msg->created_at->format('h:i A') }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="chat-input">
                    <input type="text" id="chatInput" placeholder="Type message..." />
                    <button id="sendBtn" data-chat-id="{{ $activeChat->id }}">Send</button>
                </div>
            @else
                <!-- EMPTY RIGHT SIDE -->
                <div class="chat-empty">

                    <div class="chat-empty-card">

                        <div class="chat-empty-icon">
                            💬
                        </div>

                        <h2>Your Messages</h2>

                        <p>
                            Select a conversation to start chatting with your driver.
                        </p>

                        <div class="chat-empty-actions">
                            <a href="{{ route('customer.book') }}" class="primary-btn">
                                Book a Service
                            </a>
                        </div>

                    </div>

                </div>

            @endif

        </div>

    </div>

    <div id="logoutModal" class="logout-modal hidden">

        <div class="logout-card">

            <h3>Confirm Logout</h3>
            <p>Are you sure you want to logout?</p>

            <div class="logout-actions">
                <button class="cancel-btn" onclick="closeLogoutModal()">Cancel</button>
                <button class="confirm-btn" onclick="submitLogout()">Yes, Logout</button>
            </div>

        </div>

    </div>

    <script src="{{ asset('customer/js/history.js') }}"></script>
    <script src="{{ asset('customer/js/dashboard.js') }}"></script>
    <script src="{{ asset('customer/js/chat.js') }}"></script>

@endsection
