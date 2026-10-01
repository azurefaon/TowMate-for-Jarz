<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Something went wrong') · TowMate</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f8fafc;
            color: #111827;
        }
        .error-card {
            width: 100%;
            max-width: 440px;
            padding: 36px 28px;
            text-align: center;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
        }
        .error-brand { margin: 0 0 20px; font-size: 20px; font-weight: 700; letter-spacing: -0.3px; }
        .error-brand span { color: #f5b301; }
        .error-card h1 { margin: 0 0 10px; font-size: 20px; line-height: 1.3; }
        .error-card p { margin: 0 0 24px; font-size: 15px; line-height: 1.5; color: #4b5563; }
        .error-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        .error-actions a {
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid #d1d5db;
            color: #111827;
        }
        .error-actions a.primary { background: #f5b301; border-color: #f5b301; color: #111827; }
        @media (prefers-color-scheme: dark) {
            body { background: #0f172a; color: #f1f5f9; }
            .error-card { background: #1e293b; border-color: #334155; }
            .error-card p { color: #cbd5e1; }
            .error-actions a { border-color: #475569; color: #f1f5f9; }
            .error-actions a.primary { color: #111827; }
        }
    </style>
</head>
<body>
    <main class="error-card" role="main">
        <p class="error-brand">Tow<span>Mate</span></p>
        <h1>@yield('heading')</h1>
        <p>@yield('message')</p>
        <div class="error-actions">
            <a href="javascript:history.back()">Go back</a>
            <a class="primary" href="{{ url('/') }}">Go to home</a>
        </div>
    </main>
</body>
</html>
