<!DOCTYPE html>
<html>

<head>
    <title>Jarz Quotation Ready</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #1f2937;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background: #f8fafc;
        }

        .header {
            background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
            color: white;
            padding: 36px 30px;
            text-align: center;
            border-radius: 18px 18px 0 0;
        }

        .content {
            padding: 32px 30px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-top: 0;
            border-radius: 0 0 18px 18px;
        }

        .footer {
            text-align: center;
            padding: 18px;
            font-size: 13px;
            color: #6b7280;
        }

        .status-box {
            background: #f8fafc;
            border: 1px solid #dbeafe;
            padding: 20px;
            margin: 24px 0;
            border-radius: 12px;
        }

        .note-box {
            background: #fff7ed;
            border-left: 4px solid #f59e0b;
            padding: 14px 16px;
            border-radius: 8px;
            margin: 18px 0;
        }

        .btn {
            background: #2563eb;
            color: white !important;
            padding: 14px 28px;
            text-decoration: none;
            border-radius: 10px;
            display: inline-block;
            font-weight: 600;
            font-size: 15px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0;
        }

        th,
        td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }

        th {
            width: 34%;
            color: #374151;
        }

        ul {
            padding-left: 18px;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>📩 Your quotation is ready</h1>
        <p>Dispatch reviewed your request and prepared the towing price for approval.</p>
    </div>

    <div class="content">
        <h2>Hello {{ $booking->customer->full_name ?? 'Customer' }},</h2>

        <p>
            Your booking has been reviewed by our dispatcher. Please review the initial quotation below and
            <strong>accept the price or request an adjustment</strong> from the secure review page below.
            No towing job will proceed until you approve the quotation.
        </p>

        <div class="status-box">
            <h3>Quotation Summary</h3>
            <table>
                <tr>
                    <th>Booking #</th>
                    <td>{{ $booking->job_code }}</td>
                </tr>
                <tr>
                    <th>Quotation #</th>
                    <td>{{ $booking->quotation_number ?? 'Pending' }}</td>
                </tr>
                <tr>
                    <th>Service</th>
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
                    <th>Quoted Price</th>
                    <td><strong>₱{{ number_format((float) ($booking->final_total ?? 0), 2) }}</strong></td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td><strong style="color: #2563eb;">Quoted / Waiting for Customer Approval</strong></td>
                </tr>
            </table>
        </div>

        @if (filled($booking->dispatcher_note))
            <div class="note-box">
                <strong>Dispatch note:</strong><br>
                {{ $booking->dispatcher_note }}
            </div>
        @endif

        <p><strong>Next steps</strong></p>
        <ul>
            <li>Review the quotation amount and trip details.</li>
            <li>Accept the price if you are ready to continue.</li>
            <li>If needed, request negotiation with your counter-offer or note.</li>
        </ul>

        <p style="text-align: center; margin: 32px 0;">
            <a href="{{ $reviewUrl }}" class="btn">
                Review your quotation
            </a>
        </p>

        @if (!empty($documentUrl))
            <p style="text-align: center; margin: -8px 0 24px;">
                <a href="{{ $documentUrl }}" class="btn" style="background:#111827;">
                    Open quotation document
                </a>
            </p>
        @endif

        <p>
            If you need help, contact dispatch at
            <strong>{{ config('app.dispatch_phone', '(555) 123-4567') }}</strong>.
        </p>
    </div>

    <div class="footer">
        <p>&copy; {{ date('Y') }} Jarz</p>
        <p>Dispatcher review • Customer approval • Safe towing</p>
    </div>
</body>

</html>
