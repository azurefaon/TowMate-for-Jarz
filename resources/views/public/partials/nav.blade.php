@php
    $active = $active ?? 'home';
@endphp
<nav class="jarz-nav jarz-nav--login" aria-label="Main navigation" data-nav>
    <div class="jarz-nav-inner">
        <a href="{{ url('/') }}" class="jarz-brand">
            <img src="{{ asset('dispatcher/images/jarz-logo.png') }}" alt="JARZ Towing Services"
                class="jarz-brand-logo">
            <span class="jarz-brand-name">JARZ Towing Services</span>
        </a>
        <button type="button" class="jarz-nav-toggle" aria-label="Toggle navigation menu" aria-expanded="false"
            aria-controls="jarz-nav-links" data-nav-toggle>
            <span class="jarz-nav-toggle-bars" aria-hidden="true"></span>
        </button>
        <div class="jarz-nav-links" id="jarz-nav-links" data-nav-links>
            <a href="{{ url('/') }}" class="{{ $active === 'home' ? 'is-active' : '' }}"
                @if ($active === 'home') aria-current="page" @endif>Home</a>
            <a href="{{ url('/') }}#about-us" class="{{ $active === 'about' ? 'is-active' : '' }}">About Us</a>
            <a href="{{ url('/') }}#our-services" class="{{ $active === 'services' ? 'is-active' : '' }}">Our
                Services</a>
            <a href="{{ route('login') }}" class="{{ $active === 'login' ? 'is-active' : '' }}"
                @if ($active === 'login') aria-current="page" @endif>Staff Login</a>
        </div>
    </div>
</nav>
<script>
    (function () {
        var nav = document.querySelector('[data-nav]');
        if (!nav) return;
        var toggle = nav.querySelector('[data-nav-toggle]');
        var links = nav.querySelector('[data-nav-links]');
        function setOpen(open) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            nav.classList.toggle('is-open', open);
        }
        toggle.addEventListener('click', function () {
            setOpen(toggle.getAttribute('aria-expanded') !== 'true');
        });
        links.addEventListener('click', function (event) {
            if (event.target.closest('a')) setOpen(false);
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && nav.classList.contains('is-open')) {
                setOpen(false);
                toggle.focus();
            }
        });
        window.matchMedia('(min-width: 761px)').addEventListener('change', function () {
            setOpen(false);
        });
    })();
</script>
