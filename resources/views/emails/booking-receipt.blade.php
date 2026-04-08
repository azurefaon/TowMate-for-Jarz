<div style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.6;">
    <div
        style="max-width: 680px; margin: 0 auto; padding: 36px; background: #f8fafc; border-radius: 24px; border: 1px solid #e2e8f0;">
        <div style="text-align: center; margin-bottom: 32px;">
            <h1 style="margin: 0; font-size: 28px; color: #0f172a;">Booking Confirmation</h1>
            <p style="margin: 10px 0 0; color: #475569;">Thanks for choosing TowMate. Your service request has been
                received.</p>
        </div>

        <div
            style="background: #ffffff; border-radius: 20px; padding: 24px; box-shadow: 0 16px 40px rgba(15, 23, 42, 0.05);">
            <p style="margin: 0 0 12px; color: #64748b; font-weight: 600; letter-spacing: 0.02em;">Booking ID</p>
            <h2 style="margin: 0 0 20px; font-size: 22px; color: #0f172a;">#{{ $booking->id }}</h2>

            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 10px 0; color: #334155; font-weight: 600; width: 180px;">Customer</td>
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
                @if ($booking->notes)
                    <tr>
                        <td style="padding: 10px 0; color: #334155; font-weight: 600; vertical-align: top;">Notes</td>
                        <td style="padding: 10px 0; color: #475569;">{{ $booking->notes }}</td>
                    </tr>
                @endif
            </table>
        </div>

        <div style="margin-top: 32px; padding: 24px; background: #e2e8f0; border-radius: 20px;">
            <h3 style="margin: 0 0 10px; font-size: 18px; color: #0f172a;">What happens next</h3>
            <p style="margin: 0; color: #475569;">A dispatcher will review your request and contact you shortly to
                confirm arrival time and any additional service details.</p>
        </div>

        <div style="margin-top: 28px; text-align: center; color: #94a3b8; font-size: 13px;">
            <p style="margin: 0;">If you did not submit this booking, please reply to this email or contact our support
                team immediately.</p>
        </div>
    </div>
</div>
