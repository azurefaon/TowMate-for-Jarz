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

                            <!-- FEATURED WORK -->
                            <div class="settings-field">
                                <label>Featured Work Image (Large)</label>

                                @if ($landing && $landing->portfolio_main)
                                    <img src="{{ asset('storage/' . $landing->portfolio_main) }}" class="preview-img">
                                @endif

                                <input type="file" name="portfolio_main">
                            </div>

                            <!-- COMPLETED JOBS -->
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

    </div>



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
                        value="{{ $settings['gcash_number'] ?? '09426386048' }}">
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
                    <p class="field-help">This value updates the Team Leader creation limit dynamically.</p>
                </div>

                <div class="settings-field">
                    <label>Current Usage</label>
                    <input type="text" value="{{ $teamLeaderCount ?? 0 }} / {{ $teamLeaderLimit ?? 10 }} used"
                        disabled>
                    <p class="field-help">Archive a Team Leader or raise the limit if you need more slots.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- SAVE BUTTON -->

    <div class="settings-actions">

        <button type="submit" class="settings-save">
            Save Changes
        </button>

        <button type="button" class="settings-reset">
            Reset to Defaults
        </button>

    </div>


    </form>

    </div>



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

        tabs.forEach(tab => {

            tab.addEventListener("click", () => {

                tabs.forEach(t => t.classList.remove("active"));
                tab.classList.add("active");

                contents.forEach(c => c.classList.remove("active"));

                document.getElementById(tab.dataset.tab).classList.add("active");

            });

        });
    </script>
@endsection
