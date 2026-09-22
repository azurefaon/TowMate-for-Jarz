@php
    $active = $active ?? 'home';
@endphp
<nav class="jarz-nav jarz-nav--login">
    <div class="jarz-nav-inner">
        <a href="{{ url('/') }}" class="jarz-brand">
            <img src="{{ asset('dispatcher/images/jarz-logo.png') }}" alt="JARZ Towing Services"
                class="jarz-brand-logo">
            <span class="jarz-brand-name">JARZ Towing Services</span>
        </a>
        <div class="jarz-nav-links">
            <a href="{{ url('/') }}" class="{{ $active === 'home' ? 'is-active' : '' }}">Home</a>
            <a href="{{ url('/') }}#about-us" class="{{ $active === 'about' ? 'is-active' : '' }}">About Us</a>
            <a href="{{ url('/') }}#our-services" class="{{ $active === 'services' ? 'is-active' : '' }}">Our
                Services</a>
            <a href="{{ route('login') }}" class="{{ $active === 'login' ? 'is-active' : '' }}">Staff Login</a>
        </div>
    </div>
</nav>
