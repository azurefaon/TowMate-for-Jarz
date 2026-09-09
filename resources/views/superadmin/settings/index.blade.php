@extends('layouts.superadmin')

@section('title', 'Business Settings')

@push('styles')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="{{ asset('admin/css/system-settings.css') }}?v={{ filemtime(public_path('admin/css/system-settings.css')) }}">
@endpush

@section('content')
    <div class="settings-page">

        <div class="page-top">
            <div>
                <h1>Business Settings</h1>
                <p>Manage pricing, payment details, and customer-facing app content.</p>
            </div>
        </div>

        <div class="settings-tabs">
            <button class="settings-tab active" data-tab="user-limits">Pricing &amp; Payment</button>
            <button class="settings-tab" data-tab="customer-content">Customer App Content</button>
        </div>

        <form method="POST" action="{{ route('superadmin.settings.update') }}" enctype="multipart/form-data">
            @csrf

            <div class="settings-content active" id="user-limits">
                <div class="settings-section">
                    <div class="settings-section-head">
                        <h3>Payment Details</h3>
                        <p>Payment information displayed on customer documents.</p>
                    </div>

                    <h4 class="mc-subheading">Bank Details</h4>
                    <div class="settings-grid">
                        <div class="settings-field">
                            <label>Bank Name</label>
                            <input type="text" name="settings[bank_name]" value="{{ old('settings.bank_name', $settings['bank_name'] ?? '') }}">
                        </div>

                        <div class="settings-field">
                            <label>Bank Account Name</label>
                            <input type="text" name="settings[bank_account_name]"
                                value="{{ old('settings.bank_account_name', $settings['bank_account_name'] ?? '') }}">
                        </div>

                        <div class="settings-field">
                            <label>Bank Account Number</label>
                            <input type="text" name="settings[bank_account_number]"
                                value="{{ old('settings.bank_account_number', $settings['bank_account_number'] ?? '') }}">
                        </div>
                    </div>

                    <h4 class="mc-subheading" style="margin-top: 20px;">GCash</h4>
                    <div class="settings-grid">
                        <div class="settings-field">
                            <label>GCash Name</label>
                            <input type="text" name="settings[gcash_name]"
                                value="{{ old('settings.gcash_name', $settings['gcash_name'] ?? '') }}">
                        </div>

                        <div class="settings-field">
                            <label>GCash Number</label>
                            <input type="text" name="settings[gcash_number]"
                                value="{{ old('settings.gcash_number', $settings['gcash_number'] ?? '') }}">
                        </div>
                    </div>
                </div>

                <hr class="settings-divider">

                <div class="settings-section">
                    <div class="settings-section-head">
                        <h3>Discount Settings</h3>
                        <p>PWD/Senior discount used by the pricing calculation.</p>
                    </div>

                    <div class="settings-grid">
                        <div class="settings-field">
                            <label>Discount Percentage</label>
                            <input type="number" step="0.01" min="0" max="100" name="settings[discount_percentage]"
                                value="{{ old('settings.discount_percentage', $settings['discount_percentage'] ?? '') }}">
                        </div>

                        <div class="settings-field" style="grid-column: 1 / -1;">
                            <label>Discount Reason</label>
                            <input type="text" name="settings[discount_reason]"
                                value="{{ old('settings.discount_reason', $settings['discount_reason'] ?? '') }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="settings-actions" id="main-settings-actions">
                <button type="submit" class="settings-save">Save Changes</button>
                <button type="button" class="settings-reset">Reset to Defaults</button>
            </div>

        </form>

        <div class="settings-content" id="customer-content">

            <div class="mc-subnav">
                <button class="mc-subnav-btn active" data-mc-section="mc-announcements">Announcements</button>
                <button class="mc-subnav-btn" data-mc-section="mc-services">Services</button>
                <button class="mc-subnav-btn" data-mc-section="mc-about-support">About &amp; Support</button>
                <button class="mc-subnav-btn" data-mc-section="mc-how-it-works">How It Works</button>
                <button class="mc-subnav-btn" data-mc-section="mc-coverage-areas">Coverage Areas</button>
            </div>

            <div class="mc-section active" id="mc-announcements">
                <div class="mc-section-intro">
                    <h3>Announcements</h3>
                    <p>Create notices and service advisories shown to customers.</p>
                </div>

                <h4 class="mc-subheading">Current Announcements</h4>

                @if ($mobileAnnouncements->isEmpty())
                    <p class="mc-empty">No announcements yet.</p>
                @else
                    <div class="mc-list">
                        @foreach ($mobileAnnouncements as $announcement)
                            <div class="mc-row">
                                <form method="POST" action="{{ route('superadmin.settings.customer-content.announcements.update', $announcement) }}" class="mc-row-form">
                                    @csrf
                                    @method('PATCH')
                                    <div class="settings-grid">
                                        <div class="settings-field">
                                            <label>Title</label>
                                            <input type="text" name="title" value="{{ $announcement->title }}" maxlength="150" required>
                                        </div>
                                        <div class="settings-field">
                                            <label>Start (optional)</label>
                                            <input type="datetime-local" name="start_at" value="{{ optional($announcement->start_at)->format('Y-m-d\TH:i') }}">
                                        </div>
                                        <div class="settings-field">
                                            <label>End (optional)</label>
                                            <input type="datetime-local" name="end_at" value="{{ optional($announcement->end_at)->format('Y-m-d\TH:i') }}">
                                        </div>
                                        <div class="settings-field" style="grid-column: 1 / -1;">
                                            <label>Message</label>
                                            <textarea name="message" maxlength="2000" required>{{ $announcement->message }}</textarea>
                                        </div>
                                    </div>
                                    <div class="mc-row-actions">
                                        <span class="mc-status {{ $announcement->is_active ? 'is-active' : 'is-inactive' }}">{{ $announcement->is_active ? 'Active' : 'Inactive' }}</span>
                                        <button type="submit" class="settings-save">Edit</button>
                                    </div>
                                </form>
                                <form method="POST" action="{{ route('superadmin.settings.customer-content.announcements.toggle', $announcement) }}" class="mc-toggle-form">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="settings-reset">{{ $announcement->is_active ? 'Deactivate' : 'Activate' }}</button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif

                <hr class="settings-divider">

                <h4 class="mc-subheading">Add Announcement</h4>
                <form method="POST" action="{{ route('superadmin.settings.customer-content.announcements.store') }}">
                    @csrf
                    <div class="settings-grid">
                        <div class="settings-field" style="grid-column: 1 / -1;">
                            <label>Title</label>
                            <input type="text" name="title" maxlength="150" placeholder="e.g. Holiday Schedule Notice" required>
                        </div>
                        <div class="settings-field">
                            <label>Start (optional)</label>
                            <input type="datetime-local" name="start_at">
                        </div>
                        <div class="settings-field">
                            <label>End (optional)</label>
                            <input type="datetime-local" name="end_at">
                        </div>
                        <div class="settings-field" style="grid-column: 1 / -1;">
                            <label>Message</label>
                            <textarea name="message" maxlength="2000" placeholder="Notice details shown to customers" required></textarea>
                        </div>
                    </div>
                    <p class="field-help">Leave Start/End blank to show this immediately until you deactivate it. Set either or both to schedule when it appears.</p>
                    <div class="settings-actions">
                        <button type="submit" class="settings-save">Add Announcement</button>
                    </div>
                </form>
            </div>

            <div class="mc-section" id="mc-services">
                <div class="mc-section-intro">
                    <h3>Services</h3>
                    <p>Manage service information shown in the Customer app.</p>
                </div>

                <h4 class="mc-subheading">Current Services</h4>

                @if ($mobileServices->isEmpty())
                    <p class="mc-empty">No services yet.</p>
                @else
                    <div class="mc-list">
                        @foreach ($mobileServices as $index => $service)
                            <div class="mc-row">
                                <form method="POST" action="{{ route('superadmin.settings.customer-content.services.update', $service) }}" class="mc-row-form">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="display_order" value="{{ $service->display_order }}">
                                    <div class="settings-grid">
                                        <div class="settings-field">
                                            <label>Title</label>
                                            <input type="text" name="title" value="{{ $service->title }}" maxlength="150" required>
                                        </div>
                                        <div class="settings-field">
                                            <label>Category</label>
                                            <input type="text" name="category" value="{{ $service->category }}" maxlength="100">
                                        </div>
                                        <div class="settings-field">
                                            <label>Availability note</label>
                                            <input type="text" name="availability_note" value="{{ $service->availability_note }}" maxlength="255" placeholder="e.g. 24/7 · Fastest dispatch">
                                        </div>
                                        <div class="settings-field" style="grid-column: 1 / -1;">
                                            <label>Description</label>
                                            <textarea name="description" maxlength="2000" required>{{ $service->description }}</textarea>
                                        </div>
                                    </div>
                                    <div class="mc-row-actions">
                                        <span class="mc-status {{ $service->is_active ? 'is-active' : 'is-inactive' }}">{{ $service->is_active ? 'Active' : 'Inactive' }}</span>
                                        <button type="submit" class="settings-save">Edit</button>
                                    </div>
                                </form>
                                <div class="mc-side-actions">
                                    <form method="POST" action="{{ route('superadmin.settings.customer-content.services.toggle', $service) }}" class="mc-toggle-form">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="settings-reset">{{ $service->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                    <div class="mc-order-buttons">
                                        <form method="POST" action="{{ route('superadmin.settings.customer-content.services.move', $service) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="direction" value="up">
                                            <button type="submit" class="mc-order-btn" title="Move up" @disabled($index === 0)>&uarr;</button>
                                        </form>
                                        <form method="POST" action="{{ route('superadmin.settings.customer-content.services.move', $service) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="direction" value="down">
                                            <button type="submit" class="mc-order-btn" title="Move down" @disabled($index === $mobileServices->count() - 1)>&darr;</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <hr class="settings-divider">

                <h4 class="mc-subheading">Add Service</h4>
                <form method="POST" action="{{ route('superadmin.settings.customer-content.services.store') }}">
                    @csrf
                    <div class="settings-grid">
                        <div class="settings-field">
                            <label>Title</label>
                            <input type="text" name="title" maxlength="150" placeholder="e.g. Emergency Towing" required>
                        </div>
                        <div class="settings-field">
                            <label>Category</label>
                            <input type="text" name="category" maxlength="100" placeholder="e.g. Emergency">
                        </div>
                        <div class="settings-field">
                            <label>Availability note</label>
                            <input type="text" name="availability_note" maxlength="255" placeholder="e.g. 24/7 · Fastest dispatch">
                        </div>
                        <div class="settings-field" style="grid-column: 1 / -1;">
                            <label>Description</label>
                            <textarea name="description" maxlength="2000" placeholder="Informational description shown to customers" required></textarea>
                        </div>
                    </div>
                    <div class="settings-actions">
                        <button type="submit" class="settings-save">Add Service</button>
                    </div>
                </form>
            </div>

            <div class="mc-section" id="mc-about-support">
                <div class="mc-section-intro">
                    <h3>About</h3>
                </div>

                <form method="POST" action="{{ route('superadmin.settings.customer-content.about.update') }}">
                    @csrf
                    <div class="settings-grid">
                        <div class="settings-field" style="grid-column: 1 / -1;">
                            <label>About / Mission</label>
                            <textarea name="mobile_about_text" maxlength="2000" required>{{ old('mobile_about_text', $settings['mobile_about_text'] ?? '') }}</textarea>
                        </div>
                    </div>
                    <div class="settings-actions">
                        <button type="submit" class="settings-save">Save About</button>
                    </div>
                </form>

                <hr class="settings-divider">

                <div class="mc-section-intro">
                    <h3>Customer Support</h3>
                </div>

                <form method="POST" action="{{ route('superadmin.settings.customer-content.support.update') }}">
                    @csrf
                    <div class="settings-grid">
                        <div class="settings-field">
                            <label>Support Phone</label>
                            <input type="text" name="mobile_support_phone" maxlength="20"
                                value="{{ old('mobile_support_phone', $settings['mobile_support_phone'] ?? '') }}" required>
                        </div>
                        <div class="settings-field">
                            <label>Support Email</label>
                            <input type="email" name="mobile_support_email" maxlength="150"
                                value="{{ old('mobile_support_email', $settings['mobile_support_email'] ?? '') }}" required>
                        </div>
                        <div class="settings-field">
                            <label>Office / Location</label>
                            <input type="text" name="mobile_support_location" maxlength="255"
                                value="{{ old('mobile_support_location', $settings['mobile_support_location'] ?? '') }}" required>
                        </div>
                        <div class="settings-field">
                            <label>Operating Hours</label>
                            <input type="text" name="mobile_support_hours" maxlength="255" placeholder="e.g. Available 24/7"
                                value="{{ old('mobile_support_hours', $settings['mobile_support_hours'] ?? '') }}" required>
                        </div>
                    </div>
                    <div class="settings-actions">
                        <button type="submit" class="settings-save">Save Support Info</button>
                    </div>
                </form>
            </div>

            <div class="mc-section" id="mc-how-it-works">
                <div class="mc-section-intro">
                    <h3>How It Works</h3>
                    <p>Manage the service steps shown to customers.</p>
                </div>

                <h4 class="mc-subheading">Current Steps</h4>

                @if ($mobileHowItWorksSteps->isEmpty())
                    <p class="mc-empty">No steps yet.</p>
                @else
                    <div class="mc-list">
                        @foreach ($mobileHowItWorksSteps as $index => $step)
                            <div class="mc-row">
                                <form method="POST" action="{{ route('superadmin.settings.customer-content.how-it-works.update', $step) }}" class="mc-row-form">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="display_order" value="{{ $step->display_order }}">
                                    <div class="settings-grid">
                                        <div class="settings-field">
                                            <label>Step title</label>
                                            <input type="text" name="step_title" value="{{ $step->step_title }}" maxlength="150" required>
                                        </div>
                                        <div class="settings-field" style="grid-column: 1 / -1;">
                                            <label>Step description</label>
                                            <textarea name="step_description" maxlength="2000" required>{{ $step->step_description }}</textarea>
                                        </div>
                                    </div>
                                    <div class="mc-row-actions">
                                        <span class="mc-status {{ $step->is_active ? 'is-active' : 'is-inactive' }}">{{ $step->is_active ? 'Active' : 'Inactive' }}</span>
                                        <button type="submit" class="settings-save">Edit</button>
                                    </div>
                                </form>
                                <div class="mc-side-actions">
                                    <form method="POST" action="{{ route('superadmin.settings.customer-content.how-it-works.toggle', $step) }}" class="mc-toggle-form">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="settings-reset">{{ $step->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                    <div class="mc-order-buttons">
                                        <form method="POST" action="{{ route('superadmin.settings.customer-content.how-it-works.move', $step) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="direction" value="up">
                                            <button type="submit" class="mc-order-btn" title="Move up" @disabled($index === 0)>&uarr;</button>
                                        </form>
                                        <form method="POST" action="{{ route('superadmin.settings.customer-content.how-it-works.move', $step) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="direction" value="down">
                                            <button type="submit" class="mc-order-btn" title="Move down" @disabled($index === $mobileHowItWorksSteps->count() - 1)>&darr;</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <hr class="settings-divider">

                <h4 class="mc-subheading">Add Step</h4>
                <form method="POST" action="{{ route('superadmin.settings.customer-content.how-it-works.store') }}">
                    @csrf
                    <div class="settings-grid">
                        <div class="settings-field" style="grid-column: 1 / -1;">
                            <label>Step title</label>
                            <input type="text" name="step_title" maxlength="150" placeholder="e.g. Request a Tow" required>
                        </div>
                        <div class="settings-field" style="grid-column: 1 / -1;">
                            <label>Step description</label>
                            <textarea name="step_description" maxlength="2000" placeholder="Explanatory text for this step" required></textarea>
                        </div>
                    </div>
                    <div class="settings-actions">
                        <button type="submit" class="settings-save">Add Step</button>
                    </div>
                </form>
            </div>

            <div class="mc-section" id="mc-coverage-areas">
                <div class="mc-section-intro">
                    <h3>Coverage Areas</h3>
                    <p>Manage the service areas displayed in the Customer app.</p>
                </div>

                <h4 class="mc-subheading">Current Areas</h4>

                @if ($mobileCoverageAreas->isEmpty())
                    <p class="mc-empty">No coverage areas yet.</p>
                @else
                    <div class="mc-list">
                        @foreach ($mobileCoverageAreas as $index => $area)
                            <div class="mc-row">
                                <form method="POST" action="{{ route('superadmin.settings.customer-content.coverage-areas.update', $area) }}" class="mc-row-form">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="display_order" value="{{ $area->display_order }}">
                                    <div class="settings-grid">
                                        <div class="settings-field" style="grid-column: 1 / -1;">
                                            <label>Name</label>
                                            <input type="text" name="name" value="{{ $area->name }}" maxlength="150" required>
                                        </div>
                                    </div>
                                    <div class="mc-row-actions">
                                        <span class="mc-status {{ $area->is_active ? 'is-active' : 'is-inactive' }}">{{ $area->is_active ? 'Active' : 'Inactive' }}</span>
                                        <button type="submit" class="settings-save">Edit</button>
                                    </div>
                                </form>
                                <div class="mc-side-actions">
                                    <form method="POST" action="{{ route('superadmin.settings.customer-content.coverage-areas.toggle', $area) }}" class="mc-toggle-form">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="settings-reset">{{ $area->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                    <div class="mc-order-buttons">
                                        <form method="POST" action="{{ route('superadmin.settings.customer-content.coverage-areas.move', $area) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="direction" value="up">
                                            <button type="submit" class="mc-order-btn" title="Move up" @disabled($index === 0)>&uarr;</button>
                                        </form>
                                        <form method="POST" action="{{ route('superadmin.settings.customer-content.coverage-areas.move', $area) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="direction" value="down">
                                            <button type="submit" class="mc-order-btn" title="Move down" @disabled($index === $mobileCoverageAreas->count() - 1)>&darr;</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <hr class="settings-divider">

                <h4 class="mc-subheading">Add Coverage Area</h4>
                <form method="POST" action="{{ route('superadmin.settings.customer-content.coverage-areas.store') }}" class="mc-inline-form">
                    @csrf
                    <div class="settings-field">
                        <label>Name</label>
                        <input type="text" name="name" maxlength="150" placeholder="e.g. Quezon City" required>
                    </div>
                    <button type="submit" class="settings-save">Add Coverage Area</button>
                </form>
            </div>

        </div>

    </div>

    <script>
        const tabs = document.querySelectorAll(".settings-tab");
        const contents = document.querySelectorAll(".settings-content");

        const mainActions = document.getElementById("main-settings-actions");

        tabs.forEach(tab => {

            tab.addEventListener("click", () => {

                tabs.forEach(t => t.classList.remove("active"));
                tab.classList.add("active");

                contents.forEach(c => c.classList.remove("active"));

                document.getElementById(tab.dataset.tab).classList.add("active");

                mainActions.style.display = (tab.dataset.tab === "customer-content") ? "none" : "";

            });

        });

        const mcSubnavButtons = document.querySelectorAll(".mc-subnav-btn");
        const mcSections = document.querySelectorAll(".mc-section");

        mcSubnavButtons.forEach(btn => {
            btn.addEventListener("click", () => {
                mcSubnavButtons.forEach(b => b.classList.remove("active"));
                btn.classList.add("active");

                mcSections.forEach(s => s.classList.remove("active"));
                document.getElementById(btn.dataset.mcSection).classList.add("active");
            });
        });
    </script>
@endsection
