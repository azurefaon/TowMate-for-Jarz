<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="{{ asset('admin/css/login.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('dispatcher/images/jarz-logo.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <title>TowMate | Get the App</title>
</head>

<body class="jarz-page">

    <nav class="jarz-nav jarz-nav--login">
        <div class="jarz-nav-inner">
            <div class="jarz-brand">
                <img src="{{ asset('dispatcher/images/jarz-logo.png') }}" alt="JARZ Towing Services" class="jarz-brand-logo">
                <span class="jarz-brand-name">JARZ Towing Services</span>
            </div>
        </div>
    </nav>

    <section class="jarz-getapp jarz-hero--fill">
        <div class="jarz-getapp-inner">
            <h1 class="jarz-section-heading">Get the TowMate App</h1>
            <div class="jarz-app-cards jarz-app-cards--single">
                <div class="jarz-app-card">
                    <svg class="jarz-app-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.6 9.48l1.84-3.18a.5.5 0 00-.87-.5l-1.86 3.22a11.4 11.4 0 00-9.42 0L5.43 5.8a.5.5 0 10-.87.5L6.4 9.48A10.3 10.3 0 001 18h22a10.3 10.3 0 00-5.4-8.52zM7 15a1 1 0 110-2 1 1 0 010 2zm10 0a1 1 0 110-2 1 1 0 010 2z" /></svg>
                    <h3 class="jarz-app-name">Android</h3>
                    @if (! $androidActive)
                        <p class="jarz-app-meta">TowMate is temporarily unavailable. Please check again later.</p>
                    @elseif ($apkExists)
                        @if ($apkSizeMb)
                            <p class="jarz-app-meta">{{ $apkSizeMb }} MB</p>
                        @endif
                        <a href="{{ $apkUrl }}" download="TowMate.apk" class="jarz-apk-btn">Download APK</a>
                    @else
                        <p class="jarz-app-meta">No build available yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </section>

</body>

</html>
