@php
    $facebookUrl = \App\Models\SystemSetting::getValue('company_facebook_url');
    $contactPhone = \App\Models\SystemSetting::getValue('company_phone');
    $contactPhoneDigits = $contactPhone ? ltrim(preg_replace('/\D/', '', $contactPhone), '0') : null;
    $contactPhoneHref = $contactPhoneDigits ? '+63' . $contactPhoneDigits : null;
    $contactEmail = \App\Models\SystemSetting::getValue('company_email');
@endphp
<footer class="jarz-footer">
    <div class="jarz-footer-inner">
        <div class="jarz-footer-brand">
            <span class="jarz-footer-name">JARZ Towing Services</span>
            <p class="jarz-footer-tagline">Reliable towing and roadside assistance when you need it.</p>
        </div>

        <div class="jarz-footer-contact">
            @if ($facebookUrl)
                <div class="jarz-footer-item">
                    <span class="jarz-footer-item-label">Facebook</span>
                    <a href="{{ $facebookUrl }}" target="_blank" rel="noopener" class="jarz-footer-item-link">
                        <svg class="jarz-footer-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path
                                d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5 3.66 9.15 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.51 1.49-3.9 3.77-3.9 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56v1.87h2.78l-.44 2.91h-2.34V22c4.78-.79 8.44-4.94 8.44-9.94z" />
                        </svg>
                        <span>JARZ Towing Services</span>
                    </a>
                </div>
            @endif

            @if ($contactPhone)
                <div class="jarz-footer-item">
                    <span class="jarz-footer-item-label">Contact</span>
                    <a href="tel:{{ $contactPhoneHref }}" class="jarz-footer-item-link">
                        <svg class="jarz-footer-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path
                                d="M6.6 10.8c1.3 2.6 3.4 4.7 6 6l2-2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.5.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.8c.6 0 1 .4 1 1 0 1.2.2 2.4.6 3.5.1.4 0 .8-.2 1l-2 2.3z"
                                stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                        </svg>
                        <span>{{ $contactPhone }}</span>
                    </a>
                </div>
            @endif

            @if ($contactEmail)
                <div class="jarz-footer-item">
                    <span class="jarz-footer-item-label">Email</span>
                    <a href="mailto:{{ $contactEmail }}" class="jarz-footer-item-link">
                        <svg class="jarz-footer-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M3.5 6h17a1 1 0 011 1v10a1 1 0 01-1 1h-17a1 1 0 01-1-1V7a1 1 0 011-1z"
                                stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                            <path d="M3.5 7l8.5 6.5L20.5 7" stroke="currentColor" stroke-width="1.5"
                                stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span>{{ $contactEmail }}</span>
                    </a>
                </div>
            @endif
        </div>
    </div>

    <div class="jarz-footer-bottom">
        <p class="jarz-footer-copyright">&copy; {{ date('Y') }} JARZ Towing Services. All rights reserved.</p>
        <span class="jarz-footer-wordmark">TowMate</span>
    </div>
</footer>
