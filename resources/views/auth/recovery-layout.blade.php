<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="{{ asset('admin/css/login.css') }}">
    <link rel="stylesheet" href="{{ asset('admin/css/staff-forgot-password.css') }}">
    @stack('styles')
    <link rel="icon" type="image/png" href="{{ asset('dispatcher/images/jarz-logo.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <title>@yield('title')</title>
</head>

<body class="jarz-page">
    <nav class="jarz-nav jarz-nav--login">
        <div class="jarz-nav-inner">
            <a href="{{ route('login') }}" class="jarz-brand">
                <img src="{{ asset('dispatcher/images/jarz-logo.png') }}" alt="JARZ Towing Services"
                    class="jarz-brand-logo">
                <span class="jarz-brand-name">JARZ Towing Services</span>
            </a>
            <div class="jarz-nav-links">
                <a href="{{ route('login') }}#about-us">About Us</a>
                <a href="{{ route('login') }}#our-services">Our Services</a>
            </div>
        </div>
    </nav>

    <section class="jarz-hero jarz-hero--fill">
        <div class="jarz-hero-overlay"></div>
        <div class="jarz-hero-inner">
            <div class="jarz-fp-card">
                <h1 class="jarz-fp-title">JARZ Towing Services</h1>
                <p class="jarz-fp-subtitle">@yield('subtitle')</p>

                <div class="jarz-fp-divider"></div>

                @yield('content')
            </div>
        </div>
    </section>

    @stack('scripts')
</body>

</html>
