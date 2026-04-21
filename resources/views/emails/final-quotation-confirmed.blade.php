<!DOCTYPE html>
<html>

<head>
    <title>Final Quotation Confirmed</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #1f2937;
            background: #f8fafc;
            padding: 20px;
        }

        .card {
            max-width: 640px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            overflow: hidden;
        }

        .header {
            background: #111827;
            color: #fff;
            padding: 28px;
            text-align: center;
        }

        .content {
            padding: 28px;
        }

        .summary {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 16px;
            margin: 18px 0;
        }
    </style>
</head>

<body>
    <div class="card">
        <div class="header">
            <h1 style="margin:0;">Your final quotation is confirmed</h1>
            <p style="margin:8px 0 0; opacity:0.9;">Thank you for approving the towing service quotation.</p>
        </div>
        <div class="content">
            <p>Hello {{ $booking->customer->full_name ?? 'Customer' }},</p>
            <p>Your quotation has been confirmed and the price is now locked for this booking.</p>

            <div class="summary">
                <strong>Quotation #:</strong> {{ $booking->quotation_number ?? 'Pending' }}<br>
                <strong>Final Amount:</strong> ₱{{ number_format((float) ($booking->final_total ?? 0), 2) }}<br>
                <strong>Status:</strong> CONFIRMED
            </div>

            <p>This email is your final quotation record. Please keep the amount and booking details for reference.</p>

            <p>Dispatch can now proceed with assigning the towing unit and continuing your service.</p>
        </div>
    </div>
</body>

</html>
