@extends('layouts.superadmin')

@section('title', 'System Settings')

@push('styles')
    <link rel="stylesheet" href="{{ asset('admin/css/system-settings.css') }}">
@endpush

@section('content')
    <div class="settings-page">

        <div class="settings-header">
            <h1>System Settings</h1>
            <p>Configure system preferences and options</p>
        </div>


        <!-- TABS -->

        <div class="settings-tabs">
            <button class="settings-tab active" data-tab="company">Company</button>
            <button class="settings-tab" data-tab="customize-quotation">Customize Quotations</button>
            <button class="settings-tab" data-tab="user-limits">User Limits</button>
            <button class="settings-tab" data-tab="mobile-app">Mobile App</button>
        </div>

        <div class="settings-content active" id="company">

            <form action="{{ route('superadmin.settings.landing.update') }}" method="POST" enctype="multipart/form-data">
                @csrf

                <div class="settings-card">

                    <div class="settings-card-header">
                        <h3>Company Information</h3>
                        <p>Manage landing page content</p>
                    </div>

                    <div class="settings-grid">

                        <div class="settings-field">
                            <label>Company Name</label>
                            <input type="text" name="company_name"
                                value="{{ old('company_name', $settings['company_name'] ?? 'JARZ TOWING SERVICES') }}"
                                placeholder="Company name" required>
                        </div>

                        <div class="settings-field">
                            <label>Phone</label>
                            <input type="text" name="contact_phone" value="{{ $landing->contact_phone ?? '' }}"
                                placeholder="09123456789" pattern="^09\d{9}$" maxlength="11" inputmode="numeric" required>
                        </div>

                        <div class="settings-field">
                            <label>Email</label>
                            <input type="email" name="contact_email" value="{{ $landing->contact_email ?? '' }}"
                                placeholder="company@gmail.com" pattern="^[a-zA-Z0-9._%+-]+@gmail\.com$" required>
                        </div>

                        <div class="settings-field">
                            <label>Location</label>
                            <input type="text" name="contact_location" value="{{ $landing->contact_location ?? '' }}"
                                placeholder="City, Philippines">
                        </div>



                    </div>

                    <hr class="settings-divider">

                    <div class="settings-section">
                        <h4>Landing Page Images</h4>

                        <div class="settings-grid">

                            <!-- HERO -->
                            <div class="settings-field">
                                <label>Hero Section Image (Top Banner)</label>

                                @if ($landing && $landing->hero_image)
                                    <img src="{{ asset('storage/' . $landing->hero_image) }}" class="preview-img">
                                @endif

                                <input type="file" name="hero_image">
                            </div>

                            <!-- ABOUT -->
                            <div class="settings-field">
                                <label>About Section Image</label>

                                @if ($landing && $landing->about_image)
                                    <img src="{{ asset('storage/' . $landing->about_image) }}" class="preview-img">
                                @endif

                                <input type="file" name="about_image">
                            </div>

                            <div class="settings-field">
                                <label>Featured Work Image (Large)</label>

                                @if ($landing && $landing->portfolio_main)
                                    <img src="{{ asset('storage/' . $landing->portfolio_main) }}" class="preview-img">
                                @endif

                                <input type="file" name="portfolio_main">
                            </div>

                            <div class="settings-field">
                                <label>Completed Job Image #1</label>

                                @if ($landing && $landing->portfolio_1)
                                    <img src="{{ asset('storage/' . $landing->portfolio_1) }}" class="preview-img">
                                @endif

                                <input type="file" name="portfolio_1">
                            </div>

                            <div class="settings-field">
                                <label>Completed Job Image #2</label>

                                @if ($landing && $landing->portfolio_2)
                                    <img src="{{ asset('storage/' . $landing->portfolio_2) }}" class="preview-img">
                                @endif

                                <input type="file" name="portfolio_2">
                            </div>

                            <div class="settings-field">
                                <label>Completed Job Image #3</label>

                                @if ($landing && $landing->portfolio_3)
                                    <img src="{{ asset('storage/' . $landing->portfolio_3) }}" class="preview-img">
                                @endif

                                <input type="file" name="portfolio_3">
                            </div>

                        </div>
                    </div>

                    <div class="settings-actions">
                        <button type="submit" class="settings-save">Save Landing Page</button>
                    </div>

                </div>

            </form>

        </div>

        <form method="POST" action="{{ route('superadmin.settings.update') }}" enctype="multipart/form-data">
            @csrf

    <!-- CUSTOMIZE QUOTATIONS -->

    <div class="settings-content" id="customize-quotation">

        <div class="settings-card">

            <div class="settings-card-header">
                <h3>Customize Quotations</h3>
                <p>Manage the logos, signature, and payment details used in quotations and receipts</p>
            </div>

            <div class="settings-grid">

                <div class="settings-field">
                    <label>Quotation Logo</label>
                    @if (!empty($settings['company_logo']))
                        <img src="{{ asset('storage/' . $settings['company_logo']) }}" class="preview-img">
                    @endif
                    <input type="file" name="company_logo" accept="image/*">
                </div>

                <div class="settings-field">
                    <label>Secondary Logo / Badge</label>
                    @if (!empty($settings['secondary_logo']))
                        <img src="{{ asset('storage/' . $settings['secondary_logo']) }}" class="preview-img">
                    @endif
                    <input type="file" name="secondary_logo" accept="image/*">
                </div>

                <div class="settings-field">
                    <label>Signature Image</label>
                    @if (!empty($settings['signature_image']))
                        <img src="{{ asset('storage/' . $settings['signature_image']) }}" class="preview-img">
                    @endif
                    <input type="file" name="signature_image" accept="image/*">
                    <p class="field-help">This uploaded image will appear as the signatory on quotations and
                        receipts.</p>
                </div>

                <div class="settings-field">
                    <label>Quotation Number Prefix</label>
                    <input type="text" name="settings[quote_prefix]" value="{{ $settings['quote_prefix'] ?? 'Q' }}">
                </div>

                <div class="settings-field" style="grid-column: 1 / -1;">
                    <label>Payment Terms</label>
                    <input type="text" name="settings[payment_terms]"
                        value="{{ $settings['payment_terms'] ?? 'Pay upon service confirmation' }}">
                </div>

                <div class="settings-field">
                    <label>Bank Name</label>
                    <input type="text" name="settings[bank_name]" value="{{ $settings['bank_name'] ?? 'BDO Bank' }}">
                </div>

                <div class="settings-field">
                    <label>Bank Account Name</label>
                    <input type="text" name="settings[bank_account_name]"
                        value="{{ $settings['bank_account_name'] ?? 'SEARLE ANN BARTOLOME' }}">
                </div>

                <div class="settings-field">
                    <label>Bank Account Number</label>
                    <input type="text" name="settings[bank_account_number]"
                        value="{{ $settings['bank_account_number'] ?? '012150103970' }}">
                </div>

                <div class="settings-field">
                    <label>GCash Name</label>
                    <input type="text" name="settings[gcash_name]"
                        value="{{ $settings['gcash_name'] ?? 'SHEANNE BARTOLOME FRANCHISEE' }}">
                </div>

                <div class="settings-field">
                    <label>GCash Number</label>
                    <input type="text" name="settings[gcash_number]"
                        value="{{ $settings['gcash_number'] ?? '12345678901' }}">
                </div>

                <div class="settings-field" style="grid-column: 1 / -1; margin-top: 8px;">
                    <label style="font-weight:700;">Memorandum / Dispatch Pricing</label>
                    <p class="field-help">These values drive the live customer estimate and booking computation globally.
                    </p>
                </div>

                <div class="settings-field">
                    <label>Global Base Rate</label>
                    <input type="number" step="0.01" min="0" name="settings[booking_base_rate]"
                        value="{{ $settings['booking_base_rate'] ?? '1000' }}">
                </div>

                <div class="settings-field">
                    <label>Global Rate Per KM</label>
                    <input type="number" step="0.01" min="0" name="settings[booking_per_km_rate]"
                        value="{{ $settings['booking_per_km_rate'] ?? '50' }}">
                </div>

                <div class="settings-field">
                    <label>Excess KM Threshold</label>
                    <input type="number" step="0.01" min="0" name="settings[excess_km_threshold]"
                        value="{{ $settings['excess_km_threshold'] ?? '10' }}">
                </div>

                <div class="settings-field">
                    <label>Excess KM Rate</label>
                    <input type="number" step="0.01" min="0" name="settings[excess_km_rate]"
                        value="{{ $settings['excess_km_rate'] ?? '20' }}">
                </div>

                <div class="settings-field">
                    <label>Discount Percentage</label>
                    <input type="number" step="0.01" min="0" max="100"
                        name="settings[discount_percentage]" value="{{ $settings['discount_percentage'] ?? '20' }}">
                </div>

                <div class="settings-field">
                    <label>Discount Reason</label>
                    <input type="text" name="settings[discount_reason]"
                        value="{{ $settings['discount_reason'] ?? 'PWD and senior discount' }}">
                </div>

            </div>

        </div>

    </div>

    <div class="settings-content" id="user-limits">
        <div class="settings-card">
            <div class="settings-card-header">
                <h3>Team Leader Capacity</h3>
                <p>Set the maximum number of Team Leader accounts allowed in the Add User module.</p>
            </div>

            <div class="settings-grid">
                <div class="settings-field">
                    <label>Maximum Team Leaders</label>
                    <input type="number" min="1" max="500" name="settings[max_team_leaders]"
                        value="{{ old('settings.max_team_leaders', $settings['max_team_leaders'] ?? ($teamLeaderLimit ?? 10)) }}">
                    {{-- <p class="field-help">This value updates the Team Leader creation limit dynamically.</p> --}}
                </div>

                <div class="settings-field">
                    <label>Current Usage</label>
                    <input type="text" value="{{ $teamLeaderCount ?? 0 }} / {{ $teamLeaderLimit ?? 10 }} used"
                        disabled>
                    {{-- <p class="field-help">Archive a Team Leader or raise the limit if you need more slots.</p> --}}
                </div>
            </div>
        </div>

        <div class="settings-card">
            <div class="settings-card-header">
                <h3>Deleted User Retention</h3>
                <p>How many days a user stays in the Deleted list (still restorable) before being permanently purged.</p>
            </div>

            <div class="settings-grid">
                <div class="settings-field">
                    <label>Retention Days</label>
                    <input type="number" min="1" max="365" name="settings[deleted_retention_days]"
                        value="{{ old('settings.deleted_retention_days', $settings['deleted_retention_days'] ?? 30) }}">
                </div>
            </div>
        </div>

        <div class="settings-card">
            <div class="settings-card-header">
                <h3>Customer Inactivity Lock</h3>
                <p>How many days with no login before a customer account is automatically locked. Customers can unlock their own account via Forgot Password.</p>
            </div>

            <div class="settings-grid">
                <div class="settings-field">
                    <label>Inactivity Days</label>
                    <input type="number" min="1" max="365" name="settings[customer_inactivity_lock_days]"
                        value="{{ old('settings.customer_inactivity_lock_days', $settings['customer_inactivity_lock_days'] ?? 90) }}">
                </div>
            </div>
        </div>
    </div>

    <!-- SAVE BUTTON -->

    <div class="settings-actions" id="main-settings-actions">

        <button type="submit" class="settings-save">
            Save Changes
        </button>

        <button type="button" class="settings-reset">
            Reset to Defaults
        </button>

    </div>


    </form>



    {{-- ── Mobile App Tab ──────────────────────────────────────────────────── --}}
    <div class="settings-content" id="mobile-app">

        @php
            $apkPath = public_path('downloads/towmate.apk');
            $apkExists = file_exists($apkPath);
            $apkSize = $apkExists ? round(filesize($apkPath) / 1048576, 1) . ' MB' : null;
            $apkUpdated = $apkExists ? date('M d, Y  H:i', filemtime($apkPath)) : null;
            $apkUrl = url('downloads/towmate.apk');
        @endphp

        @if (session('apk_success'))
            <div
                style="background:#f0fdf4; border:1px solid #86efac; color:#166534; padding:12px 16px; margin-bottom:18px; font-size:0.88rem; font-weight:600;">
                ✓ {{ session('apk_success') }}
            </div>
        @endif

        {{-- Download section --}}
        <div class="settings-card">
            <div class="settings-card-header">
                <h3>Download TowMate App</h3>
                <p>Scan the QR code or tap the button below to download the Android APK directly to your phone.</p>
            </div>

            @if ($apkExists)
                <div style="display:flex; align-items:center; gap:32px; flex-wrap:wrap; padding:4px 0 16px;">

                    {{-- QR Code (generated via Google Charts API — no external JS needed) --}}
                    <div style="text-align:center;">
                        <img src="https://chart.googleapis.com/chart?chs=180x180&cht=qr&chl={{ urlencode($apkUrl) }}&choe=UTF-8"
                            alt="QR Code" style="width:180px; height:180px; border:1px solid #e2e8f0;">
                        <p style="font-size:0.72rem; color:#94a3b8; margin-top:6px;">Scan with phone camera</p>
                    </div>

                    {{-- File info + download button --}}
                    <div style="flex:1; min-width:220px;">
                        <div style="display:grid; gap:8px; margin-bottom:20px;">
                            <div style="display:flex; justify-content:space-between; font-size:0.85rem;">
                                <span style="color:#64748b;">File size</span>
                                <span style="font-weight:600; color:#0f172a;">{{ $apkSize }}</span>
                            </div>
                            <div style="display:flex; justify-content:space-between; font-size:0.85rem;">
                                <span style="color:#64748b;">Last updated</span>
                                <span style="font-weight:600; color:#0f172a;">{{ $apkUpdated }}</span>
                            </div>
                            <div style="display:flex; justify-content:space-between; font-size:0.85rem;">
                                <span style="color:#64748b;">Platform</span>
                                <span style="font-weight:600; color:#0f172a;">Android (APK)</span>
                            </div>
                        </div>
                        <a href="{{ $apkUrl }}" download="TowMate.apk"
                            style="display:inline-flex; align-items:center; gap:8px; padding:11px 22px; background:#0f172a; color:#fff; font-size:0.88rem; font-weight:700; text-decoration:none; border-radius:4px;">
                            ⬇ Download TowMate APK
                        </a>
                        <p style="font-size:0.72rem; color:#94a3b8; margin-top:8px;">
                            After downloading, open the file on your Android phone.<br>
                        </p>
                    </div>

                </div>
            @else
                <div
                    style="background:#fef9c3; border:1px solid #fde047; padding:14px 16px; color:#713f12; font-size:0.85rem; margin-bottom:8px;">
                    No APK uploaded yet. Use the upload form below to add the app file.
                </div>
            @endif
        </div>

        {{-- Upload section --}}
        <div class="settings-card" style="margin-top:20px;">
            <div class="settings-card-header">
                <h3>Upload New APK</h3>
                <p>Build the APK from Flutter, then upload it here. The download link above will update immediately.</p>
            </div>

            <form action="{{ route('superadmin.settings.upload-apk') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="settings-grid">
                    <div class="settings-field">
                        <label>APK File <span style="color:#dc2626;">*</span></label>
                        <input type="file" name="apk_file" accept=".apk" required
                            style="padding:8px; border:1px solid #d1d5db; font-size:0.85rem; width:100%; box-sizing:border-box; background:#fff;">
                        <p class="field-help">Max 100 MB. Must be a valid .apk file.</p>
                        @error('apk_file')
                            <p style="color:#dc2626; font-size:0.78rem; margin-top:4px;">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
                <div style="margin-top:16px;">
                    <button type="submit" class="settings-save">Upload APK</button>
                </div>
            </form>

            <div
                style="margin-top:20px; padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; font-size:0.82rem; color:#475569; line-height:1.7;">
                <strong style="display:block; margin-bottom:4px; color:#0f172a;">How to build the APK:</strong>
                <code style="background:#e2e8f0; padding:2px 6px; border-radius:3px; font-size:0.8rem;">cd
                    towmate_app</code><br>
                <code style="background:#e2e8f0; padding:2px 6px; border-radius:3px; font-size:0.8rem;">flutter build apk
                    --release</code><br>
                Output: <code
                    style="background:#e2e8f0; padding:2px 6px; border-radius:3px; font-size:0.8rem;">build/app/outputs/flutter-apk/app-release.apk</code>
            </div>
        </div>

    </div>{{-- closes mobile-app --}}

    </div>{{-- closes settings-page --}}

    <script>
        const tabs = document.querySelectorAll(".settings-tab");
        const contents = document.querySelectorAll(".settings-content");

        const phoneInput = document.querySelector('input[name="contact_phone"]');
        const emailInput = document.querySelector('input[name="contact_email"]');

        // numbers only max 11
        phoneInput.addEventListener("input", () => {
            phoneInput.value = phoneInput.value.replace(/[^0-9]/g, "");

            if (phoneInput.value.length > 11) {
                phoneInput.value = phoneInput.value.slice(0, 11);
            }
        });

        // gmail only
        emailInput.addEventListener("input", () => {
            if (!emailInput.value.endsWith("@gmail.com")) {
                emailInput.setCustomValidity("Gmail only allowed");
            } else {
                emailInput.setCustomValidity("");
            }
        });

        const mainActions = document.getElementById("main-settings-actions");

        tabs.forEach(tab => {

            tab.addEventListener("click", () => {

                tabs.forEach(t => t.classList.remove("active"));
                tab.classList.add("active");

                contents.forEach(c => c.classList.remove("active"));

                document.getElementById(tab.dataset.tab).classList.add("active");

                mainActions.style.display = tab.dataset.tab === "mobile-app" ? "none" : "";

            });

        });
    </script>
@endsection
