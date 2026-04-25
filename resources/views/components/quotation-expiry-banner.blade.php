@props(['booking', 'quotationService'])

@php
    $timeRemaining = $quotationService->getTimeRemaining($booking);
    $isExpired = $timeRemaining['expired'] ?? false;
    $hours = $timeRemaining['hours'] ?? 0;
    $minutes = $timeRemaining['minutes'] ?? 0;
    $message = $timeRemaining['message'] ?? '';

    // Calculate urgency level
    $totalMinutes = $hours * 60 + $minutes;
    $urgencyClass = 'info';
    $urgencyIcon = '⏰';

    if ($isExpired) {
        $urgencyClass = 'expired';
        $urgencyIcon = '⏱️';
    } elseif ($totalMinutes <= 60) {
        $urgencyClass = 'urgent';
        $urgencyIcon = '⚠️';
    } elseif ($totalMinutes <= 180) {
        $urgencyClass = 'warning';
        $urgencyIcon = '⏰';
    }
@endphp

<div class="quotation-expiry-banner quotation-expiry-banner--{{ $urgencyClass }}"
    data-expires-at="{{ $booking->quotation_expires_at }}" data-booking-id="{{ $booking->id }}">

    <div class="quotation-expiry-banner__icon">
        <span class="quotation-expiry-banner__icon-emoji">{{ $urgencyIcon }}</span>
    </div>

    <div class="quotation-expiry-banner__content">
        <div class="quotation-expiry-banner__header">
            <h3 class="quotation-expiry-banner__title">
                @if ($isExpired)
                    Quotation Expired
                @else
                    Quotation Expires Soon
                @endif
            </h3>
            <span class="quotation-expiry-banner__ref">Ref: {{ $booking->quotation_number ?? $booking->job_code }}</span>
        </div>

        <div class="quotation-expiry-banner__timer" id="quotation-timer-{{ $booking->id }}">
            @if ($isExpired)
                <p class="quotation-expiry-banner__message">
                    This quotation has expired. Our dispatch team will send you an updated quotation shortly.
                </p>
            @else
                <div class="quotation-expiry-banner__countdown">
                    <div class="quotation-expiry-banner__time-unit">
                        <span class="quotation-expiry-banner__time-value"
                            data-hours>{{ str_pad($hours, 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="quotation-expiry-banner__time-label">Hours</span>
                    </div>
                    <span class="quotation-expiry-banner__time-separator">:</span>
                    <div class="quotation-expiry-banner__time-unit">
                        <span class="quotation-expiry-banner__time-value"
                            data-minutes>{{ str_pad($minutes, 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="quotation-expiry-banner__time-label">Minutes</span>
                    </div>
                </div>
                <p class="quotation-expiry-banner__message">
                    Please review and respond to this quotation before it expires.
                </p>
            @endif
        </div>

        @if (!$isExpired && in_array($booking->status, ['quoted', 'quotation_sent']))
            <div class="quotation-expiry-banner__actions">
                <a href="{{ route('customer.track', $booking) }}#quotation-response"
                    class="quotation-expiry-banner__btn quotation-expiry-banner__btn--primary">
                    Review Quotation
                </a>
            </div>
        @endif
    </div>
</div>

@push('styles')
    <style>
        .quotation-expiry-banner {
            display: flex;
            gap: 20px;
            padding: 24px;
            border-radius: 16px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            animation: slideInDown 0.4s ease-out;
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .quotation-expiry-banner--info {
            background: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
            border: 2px solid #7dd3fc;
        }

        .quotation-expiry-banner--warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fef9e7 100%);
            border: 2px solid #fbbf24;
        }

        .quotation-expiry-banner--urgent {
            background: linear-gradient(135deg, #fed7aa 0%, #ffedd5 100%);
            border: 2px solid #fb923c;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                box-shadow: 0 4px 20px rgba(251, 146, 60, 0.3);
            }

            50% {
                box-shadow: 0 4px 30px rgba(251, 146, 60, 0.5);
            }
        }

        .quotation-expiry-banner--expired {
            background: linear-gradient(135deg, #fee2e2 0%, #fef2f2 100%);
            border: 2px solid #fca5a5;
        }

        .quotation-expiry-banner__icon {
            flex-shrink: 0;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .quotation-expiry-banner__icon-emoji {
            font-size: 32px;
            animation: bounce 2s ease-in-out infinite;
        }

        @keyframes bounce {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-5px);
            }
        }

        .quotation-expiry-banner__content {
            flex: 1;
        }

        .quotation-expiry-banner__header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .quotation-expiry-banner__title {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f172a;
        }

        .quotation-expiry-banner__ref {
            font-size: 0.875rem;
            font-weight: 600;
            padding: 4px 12px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 999px;
            color: #64748b;
        }

        .quotation-expiry-banner__countdown {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .quotation-expiry-banner__time-unit {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 12px 20px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            min-width: 80px;
        }

        .quotation-expiry-banner__time-value {
            font-size: 2rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1;
            font-variant-numeric: tabular-nums;
        }

        .quotation-expiry-banner__time-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 4px;
        }

        .quotation-expiry-banner__time-separator {
            font-size: 2rem;
            font-weight: 700;
            color: #0f172a;
            animation: blink 1s ease-in-out infinite;
        }

        @keyframes blink {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.3;
            }
        }

        .quotation-expiry-banner__message {
            margin: 0;
            font-size: 0.9375rem;
            color: #475569;
            line-height: 1.6;
        }

        .quotation-expiry-banner__actions {
            margin-top: 16px;
            display: flex;
            gap: 12px;
        }

        .quotation-expiry-banner__btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 10px;
            font-size: 0.9375rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .quotation-expiry-banner__btn--primary {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .quotation-expiry-banner__btn--primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(245, 158, 11, 0.4);
        }

        .quotation-expiry-banner__btn--primary:active {
            transform: translateY(0);
        }

        /* Responsive */
        @media (max-width: 640px) {
            .quotation-expiry-banner {
                flex-direction: column;
                padding: 20px;
            }

            .quotation-expiry-banner__icon {
                width: 50px;
                height: 50px;
            }

            .quotation-expiry-banner__icon-emoji {
                font-size: 28px;
            }

            .quotation-expiry-banner__title {
                font-size: 1.125rem;
            }

            .quotation-expiry-banner__time-unit {
                padding: 10px 16px;
                min-width: 70px;
            }

            .quotation-expiry-banner__time-value {
                font-size: 1.75rem;
            }
        }
    </style>
@endpush

@push('scripts')
    <script>
        (function() {
            const banner = document.querySelector('[data-booking-id="{{ $booking->id }}"]');
            if (!banner) return;

            const expiresAt = banner.dataset.expiresAt;
            if (!expiresAt) return;

            const expiryTime = new Date(expiresAt).getTime();
            const hoursEl = banner.querySelector('[data-hours]');
            const minutesEl = banner.querySelector('[data-minutes]');

            function updateTimer() {
                const now = new Date().getTime();
                const distance = expiryTime - now;

                if (distance < 0) {
                    // Expired - reload page to show expired state
                    location.reload();
                    return;
                }

                const hours = Math.floor(distance / (1000 * 60 * 60));
                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));

                if (hoursEl) hoursEl.textContent = String(hours).padStart(2, '0');
                if (minutesEl) minutesEl.textContent = String(minutes).padStart(2, '0');

                // Update urgency class
                const totalMinutes = (hours * 60) + minutes;
                banner.classList.remove('quotation-expiry-banner--info', 'quotation-expiry-banner--warning',
                    'quotation-expiry-banner--urgent');

                if (totalMinutes <= 60) {
                    banner.classList.add('quotation-expiry-banner--urgent');
                } else if (totalMinutes <= 180) {
                    banner.classList.add('quotation-expiry-banner--warning');
                } else {
                    banner.classList.add('quotation-expiry-banner--info');
                }
            }

            // Update every minute
            updateTimer();
            setInterval(updateTimer, 60000);
        })();
    </script>
@endpush
