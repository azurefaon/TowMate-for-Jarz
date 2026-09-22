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
            <button class="settings-tab" data-tab="mobile-app">Mobile App</button>
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
                        <h3>PWD/Senior Discount</h3>
                        <p>Automatic discount applied to customer bookings that qualify for PWD/Senior pricing.</p>
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

                <hr class="settings-divider">

                <div class="settings-section">
                    <div class="settings-section-head">
                        <h3>VAT Rate</h3>
                        <p>The VAT percentage applied to every new booking estimate and quotation. Existing quotations keep the rate that was in effect when they were priced.</p>
                    </div>

                    <div class="settings-grid">
                        <div class="settings-field">
                            <label>VAT Rate (%)</label>
                            <input type="number" step="0.01" min="0" max="100" name="settings[vat_rate_percentage]"
                                value="{{ old('settings.vat_rate_percentage', $settings['vat_rate_percentage'] ?? '12') }}">
                        </div>
                    </div>
                </div>

                <div class="settings-actions" id="main-settings-actions">
                    <button type="submit" class="settings-save">Save Changes</button>
                    <button type="button" class="settings-reset">Reset to Defaults</button>
                </div>
            </form>

            <hr class="settings-divider">

            <form method="POST" action="{{ route('superadmin.settings.update') }}">
                @csrf
                <input type="hidden" name="settings[price_adjustment_form]" value="1">

                <div class="settings-section">
                    <div class="settings-section-head">
                        <h3>Price Adjustment Settings</h3>
                        <p>Controls the manual price adjustment dispatchers can apply when editing a quotation.</p>
                    </div>

                    <div class="settings-grid">
                        <div class="settings-field settings-checkbox-field">
                            <input type="checkbox" id="dispatcher_discount_enabled" name="settings[dispatcher_discount_enabled]" value="1"
                                {{ old('settings.dispatcher_discount_enabled', $settings['dispatcher_discount_enabled'] ?? '0') == '1' ? 'checked' : '' }}>
                            <label for="dispatcher_discount_enabled">Allowed Discount</label>
                        </div>

                        <div class="settings-field">
                            <label>Maximum Dispatcher Discount (%)</label>
                            <input type="number" step="0.01" min="0" max="100" name="settings[max_dispatcher_discount_percentage]"
                                value="{{ old('settings.max_dispatcher_discount_percentage', $settings['max_dispatcher_discount_percentage'] ?? '') }}">
                        </div>

                        <div class="settings-field settings-checkbox-field">
                            <input type="checkbox" id="dispatcher_discount_require_reason" name="settings[dispatcher_discount_require_reason]" value="1"
                                {{ old('settings.dispatcher_discount_require_reason', $settings['dispatcher_discount_require_reason'] ?? '0') == '1' ? 'checked' : '' }}>
                            <label for="dispatcher_discount_require_reason">Require Reason</label>
                        </div>
                    </div>
                </div>

                <div class="settings-actions">
                    <button type="submit" class="settings-save">Save Changes</button>
                </div>
            </form>

            <hr class="settings-divider">

            <form method="POST" action="{{ route('superadmin.settings.update') }}">
                @csrf
                <input type="hidden" name="settings[additional_charge_form]" value="1">

                <div class="settings-section">
                    <div class="settings-section-head">
                        <h3>Additional Charge Settings</h3>
                        <p>Controls the itemized additional charge dispatchers can apply when editing a quotation.</p>
                    </div>

                    <div class="settings-grid">
                        <div class="settings-field">
                            <label>Maximum Additional Charge (₱)</label>
                            <input type="number" step="0.01" min="0" name="settings[max_additional_charge]"
                                value="{{ old('settings.max_additional_charge', $settings['max_additional_charge'] ?? '') }}">
                        </div>

                        <div class="settings-field settings-checkbox-field">
                            <input type="checkbox" id="additional_charge_require_reason" name="settings[additional_charge_require_reason]" value="1"
                                {{ old('settings.additional_charge_require_reason', $settings['additional_charge_require_reason'] ?? '0') == '1' ? 'checked' : '' }}>
                            <label for="additional_charge_require_reason">Require Reason</label>
                        </div>
                    </div>
                </div>

                <div class="settings-actions">
                    <button type="submit" class="settings-save">Save Changes</button>
                </div>
            </form>
        </div>

        <div class="settings-content" id="customer-content">

            <div class="mc-subnav">
                <button class="mc-subnav-btn active" data-mc-section="mc-announcements">Announcements</button>
                <button class="mc-subnav-btn" data-mc-section="mc-services">Services</button>
                <button class="mc-subnav-btn" data-mc-section="mc-about-support">About &amp; Support</button>
                <button class="mc-subnav-btn" data-mc-section="mc-how-it-works">How It Works</button>
                <button class="mc-subnav-btn" data-mc-section="mc-coverage-areas">Coverage Areas</button>
                <button class="mc-subnav-btn" data-mc-section="mc-app-images">App Images</button>
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
                                <form method="POST" action="{{ route('superadmin.settings.customer-content.services.update', $service) }}" class="mc-row-form" enctype="multipart/form-data">
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
                                        <div class="settings-field" style="grid-column: 1 / -1;">
                                            <label>Image</label>
                                            @if ($service->image_path)
                                                <img src="{{ Illuminate\Support\Facades\Storage::disk('public')->url($service->image_path) }}" alt="" style="width:96px;height:64px;object-fit:cover;border-radius:6px;margin-bottom:6px;display:block;">
                                            @endif
                                            <input type="file" name="image" accept="image/*">
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
                <form method="POST" action="{{ route('superadmin.settings.customer-content.services.store') }}" enctype="multipart/form-data">
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
                        <div class="settings-field" style="grid-column: 1 / -1;">
                            <label>Image</label>
                            <input type="file" name="image" accept="image/*">
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

            <div class="mc-section" id="mc-app-images">
                <div class="mc-section-intro">
                    <h3>App Images</h3>
                    <p>Photos shown on the Customer app's public Home and About pages.</p>
                </div>

                <form method="POST" action="{{ route('superadmin.settings.customer-content.images.update') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="settings-grid">
                        <div class="settings-field">
                            <label>Home / Hero</label>
                            @if (!empty($settings['mobile_hero_image']))
                                <img class="preview-img"
                                    src="{{ mobile_content_url($settings['mobile_hero_image']) }}"
                                    alt="">
                            @endif
                            <input type="file" name="mobile_hero_image" accept="image/*">
                            <p class="field-help">Shown on the public Home hero section.</p>
                        </div>
                        <div class="settings-field">
                            <label>Services / Page</label>
                            @if (!empty($settings['mobile_services_image']))
                                <img class="preview-img"
                                    src="{{ mobile_content_url($settings['mobile_services_image']) }}"
                                    alt="">
                            @endif
                            <input type="file" name="mobile_services_image" accept="image/*">
                            <p class="field-help">Shown on the public Services page.</p>
                        </div>
                        <div class="settings-field">
                            <label>Emergency / Booking</label>
                            @if (!empty($settings['mobile_emergency_image']))
                                <img class="preview-img"
                                    src="{{ mobile_content_url($settings['mobile_emergency_image']) }}"
                                    alt="">
                            @endif
                            <input type="file" name="mobile_emergency_image" accept="image/*">
                            <p class="field-help">Shown on the Emergency Towing call-to-action.</p>
                        </div>
                        <div class="settings-field">
                            <label>About / Company</label>
                            @if (!empty($settings['mobile_about_image']))
                                <img class="preview-img"
                                    src="{{ mobile_content_url($settings['mobile_about_image']) }}"
                                    alt="">
                            @endif
                            <input type="file" name="mobile_about_image" accept="image/*">
                            <p class="field-help">Shown on the About page.</p>
                        </div>
                    </div>
                    <div class="settings-actions">
                        <button type="submit" class="settings-save">Save Images</button>
                    </div>
                </form>
            </div>

        </div>

        <div class="settings-content" id="mobile-app">
            <div class="settings-section">
                <div class="settings-section-head">
                    <h3>Android App</h3>
                    <p>Upload and manage the Android app version. The APK will be served from the download link on the sign-in page.</p>
                </div>

                @if (session('apk_success'))
                    <p class="settings-feedback settings-feedback--success">{{ session('apk_success') }}</p>
                @endif

                <div class="mobile-app-summary">
                    <div class="mobile-app-summary-item">
                        <p class="mobile-app-summary-label">Current version</p>
                        <p class="mobile-app-summary-value">{{ $androidApk['version_name'] ?: '—' }}</p>
                        <p class="mobile-app-summary-sub">{{ $androidApk['size_mb'] ? $androidApk['size_mb'] . ' MB' : 'Size unavailable' }}</p>
                    </div>
                    <div class="mobile-app-summary-item">
                        <p class="mobile-app-summary-label">Last updated</p>
                        @if ($androidApk['uploaded_at'])
                            <p class="mobile-app-summary-value">{{ \Illuminate\Support\Carbon::parse($androidApk['uploaded_at'])->format('M d, Y') }}</p>
                            <p class="mobile-app-summary-sub">{{ \Illuminate\Support\Carbon::parse($androidApk['uploaded_at'])->format('g:i A') }}</p>
                        @else
                            <p class="mobile-app-summary-value">—</p>
                        @endif
                    </div>
                    <div class="mobile-app-summary-item">
                        <p class="mobile-app-summary-label">Status</p>
                        <form method="POST" action="{{ route('superadmin.settings.mobile-app.toggle') }}" class="status-toggle-form">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="status-toggle-btn {{ $androidAppActive ? 'is-active' : 'is-inactive' }}">
                                <span class="status-toggle-track"><span class="status-toggle-thumb"></span></span>
                                <span class="status-toggle-label">{{ $androidAppActive ? 'Active' : 'Inactive' }}</span>
                            </button>
                        </form>
                    </div>
                </div>

                @if ($androidApk['release_notes'])
                    <p class="field-help" style="margin-bottom: 16px;">{{ $androidApk['release_notes'] }}</p>
                @endif

                <form method="POST" action="{{ route('superadmin.settings.upload-apk') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="settings-grid">
                        <div class="settings-field">
                            <label for="mobile_app_apk_file">APK File</label>
                            <input type="file" id="mobile_app_apk_file" name="apk_file" accept=".apk" required>
                            @error('apk_file') <small class="error-text">{{ $message }}</small> @enderror
                        </div>
                        <div class="settings-field">
                            <label for="mobile_app_version_name">Version Name</label>
                            <input type="text" id="mobile_app_version_name" name="version_name" maxlength="50" placeholder="e.g. 1.4.0" value="{{ old('version_name') }}">
                        </div>
                        <div class="settings-field" style="grid-column: 1 / -1;">
                            <label for="mobile_app_release_notes">Release Notes</label>
                            <textarea id="mobile_app_release_notes" name="release_notes" maxlength="2000" placeholder="Short summary of what changed">{{ old('release_notes') }}</textarea>
                        </div>
                    </div>
                    <div class="settings-actions">
                        <button type="submit" class="settings-save">Update APK</button>
                    </div>
                </form>
            </div>

            <hr class="settings-divider">

            <div class="settings-section">
                <div class="settings-section-head">
                    <h3>Download QR Code</h3>
                    <p>Generate a QR code that links to your app download page. Customers can scan this code to easily access the TowMate app.</p>
                </div>

                <div class="qr-card">
                    <div class="qr-left">
                        <label for="mobile_app_download_url">Download page URL</label>
                        <div class="qr-url-row">
                            <input type="text" id="mobile_app_download_url" value="{{ $appDownloadUrl }}" readonly>
                            <button type="button" class="qr-copy-btn" id="copyAppUrlBtn" title="Copy URL"><i data-lucide="copy"></i></button>
                        </div>
                        <p class="field-help">This URL will open your app download page (Android/iOS).</p>
                    </div>

                    <div class="qr-divider"></div>

                    <div class="qr-right">
                        <div class="qr-preview-box">
                            <canvas id="appQrCanvas" data-endpoint="{{ route('superadmin.settings.mobile-app.qr-code') }}"></canvas>
                        </div>
                        <div class="qr-meta">
                            <h4>QR Code</h4>
                            <p>Scan with your phone camera to open the app download page.</p>
                            <div class="qr-actions">
                                <button type="button" id="generateQrBtn" class="qr-btn qr-btn-dark"><i data-lucide="refresh-cw"></i> Generate QR Code</button>
                                <button type="button" id="downloadQrBtn" class="qr-btn qr-btn-outline"><i data-lucide="download"></i> Download QR Code</button>
                            </div>
                            <small class="error-text" id="qrGenerateError" style="display: none;"></small>
                        </div>
                    </div>
                </div>
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

@push('scripts')
    <script>
        (function () {
            const canvas = document.getElementById('appQrCanvas');
            const generateBtn = document.getElementById('generateQrBtn');
            const downloadBtn = document.getElementById('downloadQrBtn');
            const copyBtn = document.getElementById('copyAppUrlBtn');
            const urlInput = document.getElementById('mobile_app_download_url');
            const errorEl = document.getElementById('qrGenerateError');
            const ctx = canvas ? canvas.getContext('2d') : null;
            const qrEndpoint = canvas ? canvas.dataset.endpoint : null;

            function showQrError(message) {
                if (!errorEl) return;
                errorEl.textContent = message;
                errorEl.style.display = 'block';
            }

            function clearQrError() {
                if (!errorEl) return;
                errorEl.style.display = 'none';
                errorEl.textContent = '';
            }

            function renderQr() {
                if (!canvas || !ctx || !qrEndpoint) {
                    showQrError('QR preview is unavailable.');
                    return;
                }

                clearQrError();

                fetch(qrEndpoint, { cache: 'no-store' })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('QR request failed with status ' + response.status);
                        }
                        return response.blob();
                    })
                    .then(function (blob) {
                        return new Promise(function (resolve, reject) {
                            const image = new Image();
                            const objectUrl = URL.createObjectURL(blob);

                            image.onload = function () {
                                canvas.width = image.naturalWidth;
                                canvas.height = image.naturalHeight;
                                ctx.clearRect(0, 0, canvas.width, canvas.height);
                                ctx.drawImage(image, 0, 0);
                                URL.revokeObjectURL(objectUrl);
                                resolve();
                            };

                            image.onerror = function () {
                                URL.revokeObjectURL(objectUrl);
                                reject(new Error('Could not decode the QR image.'));
                            };

                            image.src = objectUrl;
                        });
                    })
                    .catch(function () {
                        showQrError('Could not generate the QR code. Please try again.');
                    });
            }

            renderQr();

            generateBtn?.addEventListener('click', renderQr);

            downloadBtn?.addEventListener('click', function () {
                if (!canvas || !canvas.width) {
                    showQrError('Generate the QR code before downloading.');
                    return;
                }

                const link = document.createElement('a');
                link.download = 'towmate-app-qr.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
            });

            copyBtn?.addEventListener('click', async function () {
                if (!urlInput) return;

                try {
                    await navigator.clipboard.writeText(urlInput.value);
                } catch (e) {
                    urlInput.select();
                    document.execCommand('copy');
                }
            });
        })();
    </script>
@endpush
