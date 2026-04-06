<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title')</title>

    <link rel="stylesheet" href="{{ asset('dispatcher/css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('dispatcher/css/dispatch.css') }}">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>

<body>

    <div class="dispatcher-wrapper">

        @include('admin-dashboard.partials.sidebar')

        <div class="main-content">

            {{-- @include('admin-dashboard.partials.topbar') --}}

            <div class="page-content">
                @yield('content')
            </div>

        </div>

    </div>

    <div class="logout-modal" id="logoutModal">
        <div class="logout-box">

            <h3>Confirm Logout</h3>
            <p>Are you sure you want to logout?</p>

            <div class="logout-actions">
                <button class="cancel-btn" onclick="closeLogoutModal()">Cancel</button>
                <button class="confirm-btn" onclick="submitLogout()">Logout</button>
            </div>

        </div>
    </div>

    <script src="{{ asset('dispatcher/js/dispatch.js') }}"></script>
    <script>
        lucide.createIcons();
    </script>

</body>

</html>
