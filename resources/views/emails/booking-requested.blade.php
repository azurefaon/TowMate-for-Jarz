@php
    $baseRate = (float) ($booking->base_rate ?? 0);
    $distanceKm = (float) ($booking->distance_km ?? 0);
    $perKmRate = (float) ($booking->per_km_rate ?? 0);
    $distanceFee = $distanceKm > 0 && $perKmRate > 0 ? $distanceKm * $perKmRate : 0;
    $estimateTotal = (float) ($booking->computed_total ?? ($booking->final_total ?? $baseRate + $distanceFee));
@endphp

<div style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.6;">
    <div
        style="max-width: 680px; margin: 0 auto; padding: 36px; background: #f8fafc; border-radius: 24px; border: 1px solid #e2e8f0;">
        <div style="text-align: center; margin-bottom: 32px;">
            <h1 style="margin: 0; font-size: 28px; color: #0f172a;">Booking Request Received</h1>
            <p style="margin: 10px 0 0; color: #475569;">Thanks for choosing Jarz. This email serves as your booking
                request receipt.</p>
        </div>

        <div
            style="background: #ffffff; border-radius: 20px; padding: 24px; box-shadow: 0 16px 40px rgba(15, 23, 42, 0.05);">
            <p style="margin: 0 0 12px; color: #64748b; font-weight: 600; letter-spacing: 0.02em;">Request status</p>
            <h2 style="margin: 0 0 20px; font-size: 22px; color: #0f172a;">REQUESTED</h2>

            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 10px 0; color: #334155; font-weight: 600; width: 180px;">Booking #</td>
                    <td style="padding: 10px 0; color: #475569;">{{ $booking->job_code }}</td>
                </tr>
                <tr>
                    <td style="padding: 10px 0; color: #334155; font-weight: 600;">Customer</td>
                    <td style="padding: 10px 0; color: #475569;">{{ $booking->customer->full_name }}</td>
                </tr>
                <tr>
                    <td style="padding: 10px 0; color: #334155; font-weight: 600;">Phone</td>
                    <td style="padding: 10px 0; color: #475569;">{{ $booking->customer->phone }}</td>
                </tr>
                @if ($booking->customer->email)
                    <tr>
                        <td style="padding: 10px 0; color: #334155; font-weight: 600;">Email</td>
                        <td style="padding: 10px 0; color: #475569;">{{ $booking->customer->email }}</td>
                    </tr>
                @endif
                <tr>
                    <td style="padding: 10px 0; color: #334155; font-weight: 600;">Vehicle Type</td>
                    <td style="padding: 10px 0; color: #475569;">{{ $booking->truckType->name ?? 'Towing Service' }}
                    </td>
                </tr>
                <tr>
                    <td style="padding: 10px 0; color: #334155; font-weight: 600;">Pickup</td>
                    <td style="padding: 10px 0; color: #475569;">{{ $booking->pickup_address }}</td>
                </tr>
                <tr>
                    <td style="padding: 10px 0; color: #334155; font-weight: 600;">Drop-off</td>
                    <td style="padding: 10px 0; color: #475569;">{{ $booking->dropoff_address }}</td>
                </tr>
            </table>
        </div>

        <div
            style="margin-top: 24px; padding: 24px; background: #fffdf4; border: 1px solid #fde68a; border-radius: 20px;">
            <h3 style="margin: 0 0 10px; font-size: 18px; color: #0f172a;">Estimated Price</h3>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px 0; color: #334155; font-weight: 600;">Base rate</td>
                    <td style="padding: 8px 0; color: #475569; text-align: right;">₱{{ number_format($baseRate, 2) }}
                    </td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #334155; font-weight: 600;">Distance fee</td>
                    <td style="padding: 8px 0; color: #475569; text-align: right;">₱{{ number_format($distanceFee, 2) }}
                    </td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #0f172a; font-weight: 700;">Estimated Total</td>
                    <td style="padding: 8px 0; color: #0f172a; text-align: right; font-weight: 700;">
                        ₱{{ number_format($estimateTotal, 2) }}</td>
                </tr>
            </table>
        </div>

        <div style="margin-top: 32px; padding: 24px; background: #e2e8f0; border-radius: 20px;">
            <h3 style="margin: 0 0 10px; font-size: 18px; color: #0f172a;">What happens next</h3>
            <p style="margin: 0; color: #475569;">Dispatch will continue reviewing your request and update the booking
                using the details already submitted.</p>
        </div>
    </div>
</div>
