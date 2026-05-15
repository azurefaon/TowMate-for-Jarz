<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>TowMate — Password Reset OTP</title>
</head>
<body style="font-family:sans-serif;background:#f8fafc;margin:0;padding:32px;">
    <div style="max-width:480px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;padding:32px;">
        <div style="font-size:1.4rem;font-weight:700;color:#0f172a;margin-bottom:8px;">TowMate</div>
        <div style="font-size:0.85rem;color:#64748b;margin-bottom:24px;border-bottom:1px solid #e2e8f0;padding-bottom:16px;">Password Reset</div>

        <p style="color:#0f172a;font-size:0.95rem;">Hi {{ $user->full_name ?? $user->name }},</p>
        <p style="color:#475569;font-size:0.9rem;">
            We received a request to reset your password. Use the OTP below to proceed.
        </p>

        <div style="background:#f1f5f9;border:1px solid #e2e8f0;padding:24px;text-align:center;margin:24px 0;">
            <div style="font-size:0.75rem;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:8px;">Your OTP</div>
            <div style="font-size:2.5rem;font-weight:700;font-family:monospace;color:#0f172a;letter-spacing:0.15em;">{{ $otp }}</div>
            <div style="font-size:0.78rem;color:#94a3b8;margin-top:8px;">Valid for 10 minutes</div>
        </div>

        <p style="color:#64748b;font-size:0.82rem;">
            If you did not request a password reset, you can safely ignore this email. Your password will not change.
        </p>
        <p style="color:#94a3b8;font-size:0.78rem;border-top:1px solid #e2e8f0;padding-top:16px;margin-top:24px;">
            &copy; {{ date('Y') }} TowMate. All rights reserved.
        </p>
    </div>
</body>
</html>
