<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TowMate Registration Code</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:'Inter',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
  <tr>
    <td align="center">
      <table width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,0.06);">
        <!-- Header -->
        <tr>
          <td style="background:#1a1a1a;padding:28px 36px;text-align:center;">
            <span style="font-size:28px;font-weight:700;color:#ffffff;">Tow</span><span style="font-size:28px;font-weight:700;color:#FFC107;">Mate</span>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:36px 36px 28px;">
            <p style="margin:0 0 8px;font-size:22px;font-weight:600;color:#1a1a1a;">Verify your email</p>
            <p style="margin:0 0 28px;font-size:14px;color:#757575;line-height:1.6;">
              Use the code below to complete your TowMate registration. This code expires in <strong>10 minutes</strong>.
            </p>
            <!-- OTP block -->
            <div style="background:#f5f5f5;border-radius:12px;padding:24px;text-align:center;margin-bottom:28px;">
              <span style="font-size:42px;font-weight:700;letter-spacing:12px;color:#1a1a1a;">{{ $otp }}</span>
            </div>
            <p style="margin:0;font-size:13px;color:#9e9e9e;line-height:1.6;">
              If you didn't request this, you can safely ignore this email. Do not share this code with anyone.
            </p>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="background:#f5f5f5;padding:20px 36px;text-align:center;border-top:1px solid #e0e0e0;">
            <p style="margin:0;font-size:12px;color:#9e9e9e;">&copy; {{ date('Y') }} TowMate. All rights reserved.</p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
