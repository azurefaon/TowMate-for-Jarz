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

        .logo-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 18px;
            padding: 12px 0 0;
        }

        .logo-bar img {
            height: 50px;
            width: auto;
            object-fit: contain;
        }

        .logo-divider {
            width: 1px;
            height: 42px;
            background: rgba(255, 255, 255, 0.35);
        }

        .btn-download {
            display: inline-block;
            margin: 18px 0 4px;
            padding: 13px 28px;
            background: #111827;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
        }
    </style>
</head>

<body>
    <div class="card">
        <div class="header">
            <div class="logo-bar">
                <img src="{{ asset('customer/image/accridetedlogo.png') }}" alt="MMDA Accredited">
                <div class="logo-divider"></div>
                <img src="{{ asset('customer/image/TowingLogo.png') }}" alt="Jarz Towing">
            </div>
            <h1 style="margin:14px 0 0;">Your service receipt is ready</h1>
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

            <p>This email already serves as your receipt copy. Please keep it for your payment reference and records.
            </p>

            @if (!empty($receiptUrl))
                <div style="text-align:center;">
                    <a href="{{ $receiptUrl }}" class="btn-download" target="_blank">⬇ Download Receipt PDF</a>
                </div>
            @endif
        </div>
    </div>
</body>

</html>
