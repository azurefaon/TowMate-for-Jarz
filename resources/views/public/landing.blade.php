<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="{{ asset('admin/css/login.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('dispatcher/images/jarz-logo.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <title>JARZ Towing Services</title>
</head>

<body class="jarz-page">
    @include('public.partials.nav', ['active' => 'home'])

    <section class="jarz-hero jarz-hero--public" id="home">
        <div class="jarz-hero-overlay"></div>
        <div class="jarz-hero-inner">
            <div class="jarz-public-hero-content">
                <p class="jarz-hero-eyebrow">Reliable Towing Support</p>
                <h1 class="jarz-hero-heading">On the Road When You Need Us</h1>
                <p class="jarz-hero-copy">Fast, safe, and professional towing services for a smoother and safer
                    journey.</p>
                <div class="jarz-hero-actions">
                    <a href="{{ route('app.download') }}" class="jarz-hero-btn jarz-hero-btn--primary">Request
                        Towing</a>
                    <a href="#about-us" class="jarz-hero-btn jarz-hero-btn--secondary">Learn More</a>
                </div>
                <div class="jarz-hero-badges">
                    <div class="jarz-hero-badge">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path
                                d="M12 3l7 3v5c0 4.5-3 8.2-7 10-4-1.8-7-5.5-7-10V6l7-3z"
                                stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                        </svg>
                        <span>Professional<br>Team</span>
                    </div>
                    <div class="jarz-hero-badge">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6" />
                            <path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                        <span>Quick<br>Response</span>
                    </div>
                    <div class="jarz-hero-badge">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path
                                d="M12 21s-6.5-5.7-6.5-11A6.5 6.5 0 0112 3.5 6.5 6.5 0 0118.5 10c0 5.3-6.5 11-6.5 11z"
                                stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                            <circle cx="12" cy="10" r="2.2" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <span>Metro Manila<br>and Nearby Areas</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="jarz-gettowmate" id="get-towmate">
        <div class="jarz-gettowmate-inner">
            <div class="jarz-gettowmate-copy-col">
                <p class="jarz-gettowmate-eyebrow">Get TowMate</p>
                <h2 class="jarz-gettowmate-heading">Towing Assistance in Your Pocket</h2>
                <p class="jarz-gettowmate-copy">Use TowMate to request towing assistance, review quotations, track
                    your service, and view your transaction history.</p>

                @if (!$androidActive)
                    <p class="jarz-gettowmate-unavailable">TowMate is temporarily unavailable. Please check again
                        later.</p>
                @else
                    <div class="jarz-download-list">
                        @if ($apkExists)
                            <a href="{{ $apkUrl }}" download="TowMate.apk" class="jarz-download-option">
                                <svg class="jarz-download-icon" viewBox="0 0 24 24" fill="currentColor"
                                    aria-hidden="true">
                                    <path
                                        d="M17.6 9.48l1.84-3.18a.5.5 0 00-.87-.5l-1.86 3.22a11.4 11.4 0 00-9.42 0L5.43 5.8a.5.5 0 10-.87.5L6.4 9.48A10.3 10.3 0 001 18h22a10.3 10.3 0 00-5.4-8.52zM7 15a1 1 0 110-2 1 1 0 010 2zm10 0a1 1 0 110-2 1 1 0 010 2z" />
                                </svg>
                                <span class="jarz-download-text">
                                    <span class="jarz-download-title">Download for Android</span>
                                    <span class="jarz-download-meta">
                                        @if ($apkVersionName)
                                            Version {{ $apkVersionName }}
                                        @endif
                                        @if ($apkVersionName && $apkSizeMb)
                                            &middot;
                                        @endif
                                        @if ($apkSizeMb)
                                            {{ $apkSizeMb }} MB
                                        @endif
                                    </span>
                                </span>
                            </a>
                        @endif
                        <span class="jarz-download-option is-disabled" aria-disabled="true">
                            <svg class="jarz-download-icon" viewBox="0 0 24 24" fill="currentColor"
                                aria-hidden="true">
                                <path
                                    d="M17.05 12.04c-.03-2.5 2.04-3.7 2.13-3.76-1.16-1.7-2.97-1.94-3.62-1.96-1.54-.16-3.02.9-3.8.9-.79 0-2-.88-3.29-.86-1.69.03-3.26.99-4.13 2.5-1.77 3.06-.45 7.6 1.26 10.08.84 1.22 1.83 2.58 3.14 2.53 1.26-.05 1.74-.81 3.27-.81 1.52 0 1.96.81 3.29.79 1.36-.02 2.22-1.22 3.05-2.45.96-1.4 1.35-2.76 1.37-2.83-.03-.01-2.63-1.01-2.67-4.13zM14.6 4.7c.7-.85 1.17-2.02 1.04-3.2-1 .04-2.23.68-2.95 1.5-.65.73-1.22 1.92-1.07 3.05 1.1.09 2.25-.56 2.98-1.35z" />
                            </svg>
                            <span class="jarz-download-text">
                                <span class="jarz-download-title">Download for iOS</span>
                                <span class="jarz-download-meta">Coming soon</span>
                            </span>
                        </span>
                    </div>
                @endif
            </div>

            @if ($androidActive)
                <div class="jarz-qr-col">
                    <div class="jarz-qr-box">
                        <p class="jarz-qr-heading">Scan to Download</p>
                        <img src="{{ route('public.mobile-app.qr-code') }}" alt="Scan to download TowMate"
                            class="jarz-qr-image">
                        <p class="jarz-qr-caption">Scan using your phone camera to get TowMate.</p>
                    </div>
                </div>
            @endif
        </div>
    </section>

    <section class="jarz-about" id="about-us">
        <div class="jarz-about-inner">
            <div class="jarz-about-copy-col">
                <p class="jarz-gettowmate-eyebrow">About Us</p>
                <h2 class="jarz-about-heading">About Us</h2>
                <p class="jarz-about-copy">JARZ Towing Services provides towing and roadside support for vehicles
                    requiring assistance. Its towing units, dispatch personnel, and field teams work together to
                    coordinate towing operations safely and efficiently.</p>
            </div>
            <div class="jarz-about-image-col">
                <img src="{{ asset('admin/images/login-background-image.png') }}" alt="JARZ Towing Services fleet"
                    class="jarz-about-image">
            </div>
        </div>
    </section>

    <section class="jarz-services" id="our-services">
        <div class="jarz-services-inner">
            <p class="jarz-gettowmate-eyebrow">Our Services</p>
            <h2 class="jarz-services-heading">Our Services</h2>
            <div class="jarz-services-cards">
                <div class="jarz-service-card">
                    <svg class="jarz-service-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path
                            d="M5 16.5v-3l1.6-4.2A2 2 0 018.5 8h7a2 2 0 011.9 1.3L19 13.5v3M5 16.5h14M5 16.5v2a1 1 0 001 1h1a1 1 0 001-1v-1M19 16.5v2a1 1 0 01-1 1h-1a1 1 0 01-1-1v-1"
                            stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                        <circle cx="8" cy="16.5" r="0.2" stroke="currentColor" stroke-width="1.6" />
                        <circle cx="16" cy="16.5" r="0.2" stroke="currentColor" stroke-width="1.6" />
                    </svg>
                    <h3 class="jarz-service-name">Light-Duty Towing</h3>
                    <p class="jarz-service-copy">Towing assistance for cars and other light vehicles.</p>
                </div>
                <div class="jarz-service-card">
                    <svg class="jarz-service-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path
                            d="M3 7h10v8H3zM13 10h4l3 3v2h-7zM6 18.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM17 18.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"
                            stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <h3 class="jarz-service-name">Medium-Duty Towing</h3>
                    <p class="jarz-service-copy">Towing assistance for medium-sized vehicles that require a larger
                        tow truck.</p>
                </div>
                <div class="jarz-service-card">
                    <svg class="jarz-service-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path
                            d="M2 7h11v8H2zM13 10h4l3 3v2h-7zM5.5 18.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM16.5 18.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"
                            stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M2 10h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                    <h3 class="jarz-service-name">Heavy-Duty Towing</h3>
                    <p class="jarz-service-copy">Towing assistance for heavy vehicles that require specialized
                        towing equipment.</p>
                </div>
            </div>
        </div>
    </section>
</body>

</html>
