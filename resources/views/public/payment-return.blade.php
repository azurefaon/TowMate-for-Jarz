<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Return to TowMate</title>
    <style>
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f6f6f6;color:#1b1b1b;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
        main{max-width:380px;margin:24px;padding:28px 24px;background:#fff;border-radius:16px;box-shadow:0 2px 14px rgba(0,0,0,.08);text-align:center}
        h1{font-size:20px;margin:0 0 10px}
        p{font-size:15px;line-height:1.5;color:#555;margin:0 0 18px}
        a.btn{display:block;padding:14px;border-radius:12px;background:#f5c400;color:#111;font-weight:700;text-decoration:none}
        small{display:block;margin-top:16px;color:#888;font-size:12px}
    </style>
</head>
<body>
<main>
    @if ($outcome === 'cancelled')
        <h1>Payment not completed</h1>
        <p>You left the payment page. You can try again from your booking in the TowMate app.</p>
    @elseif ($outcome === 'failed')
        <h1>Payment could not be completed</h1>
        <p>Open TowMate to see the latest status and try again.</p>
    @else
        <h1>Back to TowMate</h1>
        <p>Open the TowMate app to see your payment status. We confirm payments with our payment provider, so the app shows the verified result.</p>
    @endif

    <a class="btn" id="open-app" href="{{ $intent }}">Return to TowMate</a>
    <small>Using a computer? Open the TowMate app on your phone and check your booking{{ $booking ? ' ' . $booking : '' }}.</small>
</main>
</body>
</html>
