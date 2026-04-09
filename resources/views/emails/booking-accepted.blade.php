<!DOCTYPE html>
<html>

<head>
    <title>Booking Accepted</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }

        .content {
            padding: 40px 30px;
            background: #f0fdf4;
        }

        .footer {
            background: #2c3e50;
            color: white;
            text-align: center;
            padding: 20px;
            font-size: 14px;
        }

        .quote-box {
            background: white;
            border-left: 4px solid #10b981;
            padding: 25px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .btn {
            background: #10b981;
            color: white;
            padding: 15px 35px;
            text-decoration: none;
            border-radius: 8px;
            display: inline-block;
            font-weight: 600;
            font-size: 16px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        th,
        td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }

        .success-icon {
            font-size: 64px;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>✅ Booking Confirmed!</h1>
        <p>Your tow truck is on the way</p>
    </div>

    <div class="content">
        <h2>Excellent choice! Job #{{ $booking->id }} accepted</h2>

        <p>Dear {{ $booking->customer->name }},</p>

        <p>Our dispatcher has reviewed your request and assigned a truck. Here's your booking details:</p>

        <div class="quote-box">
            <h3>📋 Quotation #{{ $booking->quotation_number }}</h3>
            <table>
                <tr>
                    <th>Service</th>
                    <td>{{ $booking->truckType->name ?? 'General Towing' }}</td>
                </tr>
                <tr>
                    <th>Pickup Location</th>
                    <td>{{ $booking->pickup_location }}</td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td><strong style="color: #059669;">Accepted & Assigned</strong></td>
                </tr>
                <tr>
                    <th>ETA</th>
                    <td>{{ $booking->assigned_at->addMinutes(30)->format('g:i A') }} (est.)</td>
                </tr>
            </table>
        </div>

        <p><strong>Next steps:</strong></p>
        <ul>
            <li>📍 Truck en route - track live location in your dashboard</li>
            <li>💳 Pay quotation securely upon arrival</li>
            <li>📞 Call dispatch anytime: {{ config('app.dispatch_phone', '(555) 123-4567') }}</li>
        </ul>

        <p style="text-align: center; margin: 40px 0;">
            <a href="{{ config('app.frontend_url', 'https://towmate.com') }}/track/{{ $booking->id }}" class="btn">
                🗺️ Track Live
            </a>
        </p>
    </div>

    <div class="footer">
        <p>&copy; {{ date('Y') }} TowMate - We're on our way!</p>
        <p>Rapid response • Professional service • Satisfaction guaranteed</p>
    </div>
</body>

</html>
