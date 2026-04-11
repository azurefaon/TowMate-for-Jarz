<!DOCTYPE html>
<html>

<head>
    <title>Booking Rejected</title>
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }

        .content {
            padding: 40px 30px;
            background: #f8f9fa;
        }

        .footer {
            background: #2c3e50;
            color: white;
            text-align: center;
            padding: 20px;
            font-size: 14px;
        }

        .reject-reason {
            background: #fee;
            border-left: 4px solid #f87171;
            padding: 20px;
            margin: 20px 0;
            border-radius: 0 8px 8px 0;
        }

        .btn {
            background: #667eea;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 6px;
            display: inline-block;
            font-weight: 500;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        th,
        td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            width: 35%;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>🚛 Jarz</h1>
        <p>Your booking request has been reviewed by dispatch.</p>
    </div>

    <div class="content">
        <h2>Booking {{ $booking->job_code }} Rejected</h2>
        <p>Dear {{ $booking->customer->full_name ?? 'Customer' }},</p>

        <p>Unfortunately, your towing request could not be accommodated at this time.</p>

        <div class="reject-reason">
            <h4>Reason for Rejection:</h4>
            <p>{{ $booking->rejection_reason ?: 'No specific reason was provided by the dispatcher.' }}</p>
        </div>

        <table>
            <tr>
                <th>Service Requested</th>
                <td>{{ $booking->truckType->name ?? 'General Towing' }}</td>
            </tr>
            <tr>
                <th>Pickup</th>
                <td>{{ $booking->pickup_address ?? 'Not provided' }}</td>
            </tr>
            <tr>
                <th>Drop-off</th>
                <td>{{ $booking->dropoff_address ?? 'Not provided' }}</td>
            </tr>
            <tr>
                <th>Date Submitted</th>
                <td>{{ $booking->created_at->format('M d, Y g:i A') }}</td>
            </tr>
        </table>

        <p>We apologize for any inconvenience. You may submit a new request once you're ready.</p>

        <p style="text-align: center; margin: 30px 0;">
            <a href="{{ rtrim(config('app.url', 'http://localhost'), '/') }}/book" class="btn">Book New Tow</a>
        </p>

        <hr>

        <p>
            Need immediate help? Call our dispatch line:
            <strong>{{ config('app.dispatch_phone', '(555) 123-4567') }}</strong>
        </p>
    </div>

    <div class="footer">
        <p>&copy; {{ date('Y') }} Jarz. All rights reserved.</p>
        <p>Fast. Reliable. Always ready to tow.</p>
    </div>
</body>

</html>
