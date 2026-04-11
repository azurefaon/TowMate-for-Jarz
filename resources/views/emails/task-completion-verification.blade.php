<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Task Completion Verification</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f7f7f7;
            color: #111111;
            margin: 0;
            padding: 24px;
        }

        .card {
            max-width: 620px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 18px;
            padding: 28px;
            border: 1px solid #ececec;
            box-shadow: 0 12px 30px rgba(17, 17, 17, 0.08);
        }

        .badge {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 999px;
            background: #fff5d6;
            color: #8c6600;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .meta {
            background: #faf9f5;
            border: 1px solid #ececec;
            border-radius: 14px;
            padding: 16px;
            margin: 18px 0;
        }

        .meta p {
            margin: 0 0 8px;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 24px;
        }

        .btn {
            display: inline-block;
            padding: 12px 18px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
        }

        .btn-approve {
            background: #111111;
            color: #ffffff;
        }
    </style>
</head>

<body>
    <div class="card">
        <span class="badge">Jarz Verification Request</span>
        <h1>Please verify your towing task completion</h1>

        <p>Hello {{ $booking->customer->full_name ?? 'Customer' }},</p>
        <p>
            Your Jarz team leader has marked the job below as completed. Please confirm whether the tow service was
            finished successfully.
        </p>

        <div class="meta">
            <p><strong>Booking #:</strong> {{ $booking->job_code }}</p>
            <p><strong>Pickup:</strong> {{ $booking->pickup_address }}</p>
            <p><strong>Drop-off:</strong> {{ $booking->dropoff_address }}</p>
            <p><strong>Truck Type:</strong> {{ $booking->truckType->name ?? 'General Towing' }}</p>
            @if ($booking->customer_verification_note)
                <p><strong>Team Note:</strong> {{ $booking->customer_verification_note }}</p>
            @endif
        </div>

        <p>Please confirm the completed service below:</p>

        <div class="actions">
            <a href="{{ $approveUrl }}" class="btn btn-approve">✔ Yes, service is completed</a>
        </div>

        <p style="margin-top: 24px; color: #6b7280;">
            This secure confirmation link expires automatically after 24 hours.
        </p>
    </div>
</body>

</html>
