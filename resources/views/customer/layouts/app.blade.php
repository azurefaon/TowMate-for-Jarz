<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Jarz Customer</title>

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="stylesheet" href="{{ asset('customer/css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('customer/css/dashboard.css') }}">
    <style>
        :root {
            --jarz-accent: #FFF200;
            --jarz-bg: #F5F5F5;
            --jarz-surface: #D6E6F2;
            --jarz-text: #303841;
            --jarz-line: #c4d7e5;
        }

        body {
            background: var(--jarz-bg);
            color: var(--jarz-text);
        }

        .customer-profile-menu {
            position: relative;
        }

        .customer-profile-menu summary {
            list-style: none;
            cursor: pointer;
        }

        .customer-profile-menu summary::-webkit-details-marker {
            display: none;
        }

        .customer-profile-card {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #fff;
            border: 1px solid var(--jarz-line);
        }

        .customer-profile-avatar {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--jarz-surface);
            color: var(--jarz-text);
            font-weight: 700;
        }

        .customer-profile-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + 8px);
            min-width: 180px;
            padding: 8px;
            border-radius: 14px;
            background: #fff;
            border: 1px solid var(--jarz-line);
            box-shadow: 0 18px 40px rgba(48, 56, 65, .12);
            z-index: 20;
        }

        .customer-profile-dropdown a,
        .customer-profile-dropdown button {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 0;
            background: transparent;
            padding: 10px 12px;
            border-radius: 10px;
            color: var(--jarz-text);
            text-decoration: none;
            cursor: pointer;
        }

        .customer-profile-dropdown a:hover,
        .customer-profile-dropdown button:hover {
            background: var(--jarz-surface);
        }

        .customer-logout-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 60;
        }

        .customer-logout-modal.is-open {
            display: flex;
        }

        .customer-logout-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, .5);
        }

        .customer-logout-card {
            position: relative;
            width: calc(100% - 24px);
            max-width: 420px;
            background: #fff;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 20px 48px rgba(15, 23, 42, .14);
        }

        .customer-logout-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 18px;
        }

        .customer-logout-actions button {
            border: 0;
            border-radius: 10px;
            padding: 10px 14px;
            cursor: pointer;
        }

        .customer-logout-actions .secondary {
            background: var(--jarz-surface);
            color: var(--jarz-text);
        }

        .customer-logout-actions .primary {
            background: var(--jarz-accent);
            color: var(--jarz-text);
        }
    </style>

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

    <div class="customer-logout-modal" id="customerLogoutModal" aria-hidden="true">
        <div class="customer-logout-backdrop" onclick="closeCustomerLogoutModal()"></div>
        <div class="customer-logout-card" role="dialog" aria-modal="true">
            <h3>Logout from Jarz?</h3>
            <p>Your current customer session will close securely.</p>
            <div class="customer-logout-actions">
                <button type="button" class="secondary" onclick="closeCustomerLogoutModal()">Stay here</button>
                <button type="button" class="primary" onclick="submitCustomerLogout()">Logout</button>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('logout') }}" id="customerLogoutForm" style="display:none;">
        @csrf
    </form>

    <script src="{{ asset('customer/js/app.js') }}"></script>

    <script>
        window.openCustomerLogoutModal = function() {
            document.getElementById('customerLogoutModal')?.classList.add('is-open');
        };

        window.closeCustomerLogoutModal = function() {
            document.getElementById('customerLogoutModal')?.classList.remove('is-open');
        };

        window.submitCustomerLogout = function() {
            document.getElementById('customerLogoutForm')?.submit();
        };

        lucide.createIcons();
    </script>



</body>

</html>
