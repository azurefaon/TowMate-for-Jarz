<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation {{ $quotation->quotation_number }} - TowMate</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes pulse-slow {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.7;
            }
        }

        .pulse-slow {
            animation: pulse-slow 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="max-w-4xl mx-auto px-4 py-8">

        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">Your Towing Service Quotation</h1>
            <p class="text-gray-600">Reference: <span
                    class="font-semibold text-blue-600">{{ $quotation->quotation_number }}</span></p>
        </div>

        <!-- Success/Error Messages -->
        @if (session('success'))
            <div class="mb-6 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-6 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">
                {{ session('error') }}
            </div>
        @endif

        @if (!empty($isOutdated) && $isOutdated)
            <div class="mb-6 px-5 py-4 rounded-lg" style="background:#fef3c7;border:2px solid #f59e0b;">
                <p class="font-bold text-lg" style="color:#92400e;">Quotation Updated</p>
                <p class="text-sm mt-1" style="color:#78350f;">
                    The dispatcher has revised this quotation after your link was sent.
                    This link is no longer valid for acceptance.
                    Please wait — the dispatcher will send you a new link with the updated details.
                </p>
            </div>
        @endif

        <!-- Status Banner -->
        @php
            $timeRemaining = $quotation->getTimeRemaining();
            $urgency = $timeRemaining['urgency'] ?? 'normal';

            $bannerColors = [
                'urgent' => 'bg-red-50 border-red-300 text-red-800',
                'warning' => 'bg-yellow-50 border-yellow-300 text-yellow-800',
                'normal' => 'bg-blue-50 border-blue-300 text-blue-800',
                'expired' => 'bg-gray-50 border-gray-300 text-gray-800',
                'accepted' => 'bg-green-50 border-green-300 text-green-800',
                'rejected' => 'bg-gray-50 border-gray-300 text-gray-800',
            ];

            $statusBanner =
                $bannerColors[
                    $quotation->status === 'accepted'
                        ? 'accepted'
                        : ($quotation->status === 'rejected'
                            ? 'rejected'
                            : $urgency)
                ] ?? $bannerColors['normal'];
        @endphp

        @if ($quotation->status === 'sent' && !$timeRemaining['expired'])
            <div
                class="mb-6 border-2 {{ $statusBanner }} px-6 py-4 rounded-lg {{ $urgency === 'urgent' ? 'pulse-slow' : '' }}">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="font-semibold text-lg">
                            @if ($urgency === 'urgent')
                                ⏰ Expires Soon!
                            @elseif($urgency === 'warning')
                                ⚠️ Expiring Soon
                            @else
                                ✅ Active Quotation
                            @endif
                        </p>
                        <p class="text-sm mt-1" id="countdown">{{ $timeRemaining['message'] }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm opacity-75">Sent on</p>
                        <p class="font-semibold">{{ $quotation->sent_at->format('M d, Y h:i A') }}</p>
                    </div>
                </div>
            </div>
        @elseif($quotation->status === 'accepted')
            <div class="mb-6 border-2 bg-green-50 border-green-300 text-green-800 px-6 py-4 rounded-lg">
                <p class="font-semibold text-lg">✅ Quotation Accepted</p>
                <p class="text-sm mt-1">Your booking has been confirmed!</p>
            </div>
        @elseif($quotation->status === 'rejected')
            <div class="mb-6 border-2 bg-gray-50 border-gray-300 text-gray-800 px-6 py-4 rounded-lg">
                <p class="font-semibold text-lg">❌ Quotation Rejected</p>
                <p class="text-sm mt-1">You can request a new quotation anytime.</p>
            </div>
        @elseif($quotation->status === 'pending')
            <div class="mb-6 border-2 bg-yellow-50 border-yellow-300 text-yellow-800 px-6 py-4 rounded-lg">
                <p class="font-semibold text-lg">⏳ Under Review</p>
                <p class="text-sm mt-1">Our team is reviewing your negotiation request.</p>
            </div>
        @else
            <div class="mb-6 border-2 bg-gray-50 border-gray-300 text-gray-800 px-6 py-4 rounded-lg">
                <p class="font-semibold text-lg">⏰ Quotation Expired</p>
                <p class="text-sm mt-1">This quotation has expired. Please contact us for a new quotation.</p>
            </div>
        @endif

        <!-- Main Content Card -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden mb-6">

            <!-- Customer Info -->
            <div class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-6 py-4">
                <h2 class="text-xl font-semibold mb-2">Customer Information</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                    <div>
                        <p class="opacity-75">Name</p>
                        <p class="font-semibold">{{ $quotation->customer->name }}</p>
                    </div>
                    <div>
                        <p class="opacity-75">Phone</p>
                        <p class="font-semibold">{{ $quotation->customer->phone }}</p>
                    </div>
                    @if ($quotation->customer->email)
                        <div>
                            <p class="opacity-75">Email</p>
                            <p class="font-semibold">{{ $quotation->customer->email }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Service Details -->
            <div class="px-6 py-5 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Service Details</h3>

                <div class="space-y-4">
                    <!-- Pickup -->
                    <div class="flex items-start">
                        <div
                            class="flex-shrink-0 w-8 h-8 bg-green-100 rounded-full flex items-center justify-center mr-3">
                            <span class="text-green-600 font-bold">A</span>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm text-gray-600">Pickup Location</p>
                            <p class="font-semibold text-gray-900">{{ $quotation->pickup_address }}</p>
                            @if ($quotation->pickup_notes)
                                <p class="text-sm text-gray-500 mt-1">Note: {{ $quotation->pickup_notes }}</p>
                            @endif
                        </div>
                    </div>

                    <!-- Dropoff -->
                    <div class="flex items-start">
                        <div
                            class="flex-shrink-0 w-8 h-8 bg-red-100 rounded-full flex items-center justify-center mr-3">
                            <span class="text-red-600 font-bold">B</span>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm text-gray-600">Drop-off Location</p>
                            <p class="font-semibold text-gray-900">{{ $quotation->dropoff_address }}</p>
                        </div>
                    </div>

                    <!-- Distance & Vehicle -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
                        <div class="bg-gray-50 rounded-lg p-3">
                            <p class="text-sm text-gray-600">Distance</p>
                            <p class="font-semibold text-gray-900">{{ number_format($quotation->distance_km, 2) }} km
                            </p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-3">
                            <p class="text-sm text-gray-600">Vehicle Type</p>
                            <p class="font-semibold text-gray-900">{{ $quotation->truckType->name }}</p>
                        </div>
                    </div>

                    <!-- Vehicle Details -->
                    @if ($quotation->vehicle_make || $quotation->vehicle_model)
                        <div class="bg-blue-50 rounded-lg p-4 mt-4">
                            <p class="text-sm font-semibold text-blue-900 mb-2">Your Vehicle</p>
                            <p class="text-gray-700">
                                @if ($quotation->vehicle_year)
                                    {{ $quotation->vehicle_year }}
                                @endif
                                {{ $quotation->vehicle_make }} {{ $quotation->vehicle_model }}
                                @if ($quotation->vehicle_color)
                                    - {{ $quotation->vehicle_color }}
                                @endif
                            </p>
                            @if ($quotation->vehicle_plate_number)
                                <p class="text-sm text-gray-600 mt-1">Plate: {{ $quotation->vehicle_plate_number }}</p>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            <!-- Price Breakdown -->
            <div class="px-6 py-5 bg-gray-50">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Price Breakdown</h3>

                <div class="space-y-3">
                    <div class="flex justify-between text-gray-700">
                        <span>Base Rate ({{ $quotation->truckType->name }})</span>
                        <span>₱{{ number_format($quotation->truckType->base_price ?? 0, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-gray-700">
                        <span>Distance Charge ({{ number_format($quotation->distance_km, 2) }} km)</span>
                        <span>₱{{ number_format($quotation->estimated_price - ($quotation->truckType->base_price ?? 0), 2) }}</span>
                    </div>

                    @if ($quotation->counter_offer_amount)
                        <div class="flex justify-between text-blue-600 border-t border-gray-300 pt-3">
                            <span class="font-semibold">Your Counter Offer</span>
                            <span
                                class="font-semibold">₱{{ number_format($quotation->counter_offer_amount, 2) }}</span>
                        </div>
                    @endif

                    <div class="flex justify-between text-xl font-bold text-gray-900 border-t-2 border-gray-300 pt-3">
                        <span>Total Amount</span>
                        <span class="text-blue-600">₱{{ number_format($quotation->estimated_price, 2) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        @if ($quotation->status === 'sent' && !$timeRemaining['expired'])
            <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
                @if (!empty($isOutdated) && $isOutdated)
                    <h3 class="text-lg font-semibold mb-3" style="color:#92400e;">Waiting for Updated Quotation</h3>
                    <p class="text-sm" style="color:#78350f;">
                        The dispatcher has updated this quotation. A new link will be sent to your email shortly.
                        You do not need to do anything — just wait for the new message.
                    </p>
                @else
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Ready to proceed?</h3>
                    <div class="text-center">
                        <a href="{{ $signedAcceptUrl }}"
                            class="bg-green-600 hover:bg-green-700 text-white font-bold text-lg py-4 px-12 rounded-lg">
                            ✅ Accept & Continue
                        </a>
                        <p class="text-sm text-gray-500 mt-4">By accepting, you agree to the quoted price and service terms.</p>
                    </div>
                @endif
            </div>
        @endif

        <!-- Footer -->
        <div class="text-center text-gray-600 text-sm">
            <p class="mb-2">Need help? Contact us:</p>
            <p class="font-semibold">Phone: (123) 456-7890 | Email: support@towmate.com</p>
            <p class="mt-4 text-xs text-gray-500">© {{ date('Y') }} TowMate. All rights reserved.</p>
        </div>
    </div>

    <script>
        // Countdown timer
        @if ($quotation->status === 'sent' && !$timeRemaining['expired'])
            const expiresAt = new Date('{{ $quotation->expires_at->toIso8601String() }}').getTime();

            function updateCountdown() {
                const now = new Date().getTime();
                const distance = expiresAt - now;

                if (distance < 0) {
                    document.getElementById('countdown').textContent = 'Expired';
                    setTimeout(() => location.reload(), 2000);
                    return;
                }

                const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));

                let message = '';
                if (days > 0) {
                    message = `${days}d ${hours}h ${minutes}m remaining`;
                } else if (hours > 0) {
                    message = `${hours}h ${minutes}m remaining`;
                } else {
                    message = `${minutes}m remaining`;
                }

                document.getElementById('countdown').textContent = message;
            }

            updateCountdown();
            setInterval(updateCountdown, 60000); // Update every minute
        @endif
    </script>
</body>

</html>
