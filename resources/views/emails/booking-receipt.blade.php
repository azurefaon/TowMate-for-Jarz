<!DOCTYPE html>
<html>

<head>
    <title>Your Jarz Service Receipt</title>
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

        .btn {
            display: inline-block;
            padding: 12px 22px;
            background: #facc15;
            color: #111827 !important;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 700;
        }
    </style>
</head>

<body>
    <div class="card">
        <div class="header">
            <h1 style="margin:0;">Your service receipt is ready</h1>
            <p style="margin:8px 0 0; opacity:0.9;">Thank you for using Jarz.</p>
        </div>

        <div class="content">
            <p>Hello {{ $booking->customer->full_name ?? 'Customer' }},</p>
            <p>Your towing job has been completed successfully. Your official receipt is now available.</p>

            <div class="summary">
                <strong>Receipt #:</strong> {{ $booking->receipt->receipt_number ?? 'Pending' }}<br>
                <strong>Quotation #:</strong> {{ $booking->quotation_number ?? 'Pending' }}<br>
                <strong>Service:</strong> {{ $booking->truckType->name ?? 'Towing Service' }}<br>
                <strong>Total:</strong> ₱{{ number_format((float) ($booking->final_total ?? 0), 2) }}
            </div>

            @if (!empty($receiptUrl))
                <p style="text-align:center; margin:24px 0;">
                    <a href="{{ $receiptUrl }}" class="btn">Open receipt</a>
                </p>
            @endif

            <p>Please keep this for your payment reference and records.</p>
        </div>
    </div>
</body>

</html>
