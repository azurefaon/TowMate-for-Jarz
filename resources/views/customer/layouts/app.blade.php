<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>TowMate Customer</title>

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="stylesheet" href="{{ asset('customer/css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('customer/css/dashboard.css') }}">

    <!-- ICONS -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>

<body>

    {{-- SIDEBAR --}}
    @include('customer.partials.sidebar')

    <div class="main-content">

        {{-- TOPBAR --}}
        @include('customer.partials.topbar')

        {{-- PAGE CONTENT --}}
        <div class="page-content">
            @yield('content')
        </div>

        {{-- FOOTER --}}
        @include('customer.components.footer')

    </div>
    @include('customer.partials.mobile-nav')
    <script src="{{ asset('customer/js/app.js') }}"></script>

    <script>
        lucide.createIcons();
    </script>



</body>

</html>
