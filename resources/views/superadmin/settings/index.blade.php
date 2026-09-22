@extends('layouts.superadmin')

@section('title', 'Business Settings')

@push('styles')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap">
    <link rel="stylesheet"
        href="{{ asset('admin/css/system-settings.css') }}?v={{ filemtime(public_path('admin/css/system-settings.css')) }}">
@endpush

@section('content')
    <div class="settings-page">

        <div class="page-top">
            <div>
                <h1>Business Settings</h1>
                <p>Manage business settings and customer-facing app content.</p>
            </div>
        </div>

        <div class="settings-tabs">
            <button class="settings-tab active" data-tab="user-limits">Company Settings</button>
            <button class="settings-tab" data-tab="customer-content">Customer App Content</button>
            <button class="settings-tab" data-tab="mobile-app">Mobile App</button>
        </div>

        <div class="settings-content active" id="user-limits">
            <div class="settings-section">
                <div class="settings-section-head">
                    <h3>Business Information</h3>
                    <p>Company details used across the app and on generated documents.</p>
                </div>

                <form method="POST" action="{{ route('superadmin.settings.update') }}">
                    @csrf
                    <input type="hidden" name="settings[business_info_form]" value="1">

                    <div class="settings-grid">
                        <div class="settings-field">
                            <label for="company_name">Company Name</label>
                            <input type="text" id="company_name" name="settings[company_name]"
                                value="{{ old('settings.company_name', $settings['company_name'] ?? '') }}" required>
                            @error('settings.company_name') <small class="error-text">{{ $message }}</small> @enderror
                        </div>

                        <div class="settings-field">
                            <label for="company_email">Business Email</label>
                            <input type="email" id="company_email" name="settings[company_email]"
                                value="{{ old('settings.company_email', $settings['company_email'] ?? '') }}" required>
                            @error('settings.company_email') <small class="error-text">{{ $message }}</small> @enderror
                        </div>

                        <div class="settings-field">
                            <label for="company_phone">Contact Number</label>
                            <input type="text" id="company_phone" name="settings[company_phone]"
                                placeholder="e.g. 09171234567"
                                value="{{ old('settings.company_phone', $settings['company_phone'] ?? '') }}" required>
                            @error('settings.company_phone') <small class="error-text">{{ $message }}</small> @enderror
                        </div>

                        <div class="settings-field" style="grid-column: 1 / -1;">
                            <label for="company_address">Business Address</label>
                            <input type="text" id="company_address" name="settings[company_address]"
                                value="{{ old('settings.company_address', $settings['company_address'] ?? '') }}" required>
                            @error('settings.company_address') <small class="error-text">{{ $message }}</small> @enderror
                        </div>
                    </div>

                    <div class="settings-actions">
                        <button type="submit" class="settings-save">Save Changes</button>
                    </div>
                </form>
            </div>

            <hr class="settings-divider">

            <div class="settings-section">
                <div class="settings-section-head">
                    <h3>Tax Settings</h3>
                    <p>Set the VAT rate applied to applicable transactions.</p>
                </div>

                <form method="POST" action="{{ route('superadmin.settings.update') }}">
                    @csrf

                    <div class="settings-grid">
                        <div class="settings-field">
                            <label for="vat_rate_percentage">VAT Percentage (%)</label>
                            <input type="number" id="vat_rate_percentage" step="0.01" min="0" max="100"
                                name="settings[vat_rate_percentage]"
                                value="{{ old('settings.vat_rate_percentage', $settings['vat_rate_percentage'] ?? '12') }}">
                        </div>
                    </div>

                    <div class="settings-actions">
                        <button type="submit" class="settings-save">Save Changes</button>
                    </div>
                </form>
            </div>
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
                                <form method="POST"
                                    action="{{ route('superadmin.settings.customer-content.announcements.update', $announcement) }}"
                                    class="mc-row-form">
                                    @csrf
                                    @method('PATCH')
                                    <div class="settings-grid">
                                        <div class="settings-field">
                                            <label>Title</label>
                                            <input type="text" name="title" value="{{ $announcement->title }}"
                                                maxlength="150" required>
                                        </div>
                                        <div class="settings-field">
                                            <label>Start (optional)</label>
                                            <input type="datetime-local" name="start_at"
                                                value="{{ optional($announcement->start_at)->format('Y-m-d\TH:i') }}">
                                        </div>
                                        <div class="settings-field">
                                            <label>End (optional)</label>
                                            <input type="datetime-local" name="end_at"
                                                value="{{ optional($announcement->end_at)->format('Y-m-d\TH:i') }}">
                                        </div>
                                        <div class="settings-field" style="grid-column: 1 / -1;">
                                            <label>Message</label>
                                            <textarea name="message" maxlength="2000" required>{{ $announcement->message }}</textarea>
                                        </div>
                                    </div>
                                    <div class="mc-row-actions">
                                        <span
                                            class="mc-status {{ $announcement->is_active ? 'is-active' : 'is-inactive' }}">{{ $announcement->is_active ? 'Active' : 'Inactive' }}</span>
                                        <button type="submit" class="settings-save">Edit</button>
                                    </div>
                                </form>
                                <form method="POST"
                                    action="{{ route('superadmin.settings.customer-content.announcements.toggle', $announcement) }}"
                                    class="mc-toggle-form">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit"
                                        class="settings-reset">{{ $announcement->is_active ? 'Deactivate' : 'Activate' }}</button>
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
                            <input type="text" name="title" maxlength="150" placeholder="e.g. Holiday Schedule Notice"
                                required>
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
                    <p class="field-help">Leave Start/End blank to show this immediately until you deactivate it. Set
                        either or both to schedule when it appears.</p>
                    <div class="settings-actions">
                        <button type="submit" class="settings-save">Add Announcement</button>
                    </div>
                </form>
            </div>

            <div class="mc-section" id="mc-services">
                <div class="mc-section-intro mc-section-intro-with-action">
                    <div>
                        <h3>Services</h3>
                        <p>Manage service information shown in the Customer app.</p>
                    </div>
                    <button type="button" class="settings-save" data-modal-target="add-service-modal">
                        <i data-lucide="plus"></i> Add Service
                    </button>
                </div>

                @if ($mobileServices->isEmpty())
                    <p class="mc-empty">No services yet.</p>
                @else
                    <div class="mc-list">
                        @foreach ($mobileServices as $index => $service)
                            <div class="mc-service-row">
                                @if ($service->image_path)
                                    <img class="mc-service-thumb"
                                        src="{{ mobile_content_url($service->image_path) }}" alt="">
                                @else
                                    <div class="mc-service-thumb mc-service-thumb-empty"></div>
                                @endif

                                <div class="mc-service-body">
                                    <div class="mc-service-top">
                                        <span class="mc-service-title">{{ $service->title }}</span>
                                        <span
                                            class="mc-status {{ $service->is_active ? 'is-active' : 'is-inactive' }}">{{ $service->is_active ? 'Active' : 'Inactive' }}</span>
                                    </div>
                                    @if ($service->category)
                                        <p class="mc-service-category">Category: {{ $service->category }}</p>
                                    @endif
                                    <p class="mc-service-desc">{{ $service->description }}</p>
                                    @if ($service->availability_note)
                                        <p class="mc-service-note">{{ $service->availability_note }}</p>
                                    @endif
                                </div>

                                <div class="mc-service-actions">
                                    <div class="mc-order-buttons">
                                        <form method="POST"
                                            action="{{ route('superadmin.settings.customer-content.services.move', $service) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="direction" value="up">
                                            <button type="submit" class="mc-order-btn" title="Move up"
                                                @disabled($index === 0)>&uarr;</button>
                                        </form>
                                        <form method="POST"
                                            action="{{ route('superadmin.settings.customer-content.services.move', $service) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="direction" value="down">
                                            <button type="submit" class="mc-order-btn" title="Move down"
                                                @disabled($index === $mobileServices->count() - 1)>&darr;</button>
                                        </form>
                                    </div>
                                    <button type="button" class="settings-reset"
                                        data-modal-target="edit-service-modal-{{ $service->id }}">Edit</button>
                                    <form method="POST"
                                        action="{{ route('superadmin.settings.customer-content.services.toggle', $service) }}"
                                        class="mc-toggle-form">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit"
                                            class="settings-reset">{{ $service->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @foreach ($mobileServices as $service)
                        <div class="mc-modal-overlay" id="edit-service-modal-{{ $service->id }}">
                            <div class="mc-modal">
                                <div class="mc-modal-header">
                                    <h3>Edit Service</h3>
                                    <button type="button" class="mc-modal-close" data-modal-close
                                        aria-label="Close">
                                        <i data-lucide="x"></i>
                                    </button>
                                </div>

                                <form method="POST"
                                    action="{{ route('superadmin.settings.customer-content.services.update', $service) }}"
                                    enctype="multipart/form-data">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="display_order" value="{{ $service->display_order }}">

                                    <div class="mc-modal-body">
                                        <div class="settings-field">
                                            <label>Title</label>
                                            <input type="text" name="title" value="{{ $service->title }}"
                                                maxlength="150" required>
                                        </div>
                                        <div class="settings-field">
                                            <label>Category</label>
                                            <input type="text" name="category" value="{{ $service->category }}"
                                                maxlength="100">
                                        </div>
                                        <div class="settings-field">
                                            <label>Availability Note</label>
                                            <input type="text" name="availability_note"
                                                value="{{ $service->availability_note }}" maxlength="255"
                                                placeholder="e.g. 24/7 · Fastest dispatch">
                                        </div>
                                        <div class="settings-field">
                                            <label>Description</label>
                                            <textarea name="description" maxlength="2000" required>{{ $service->description }}</textarea>
                                        </div>
                                        <div class="settings-field">
                                            <label>Service Image</label>
                                            <div class="mc-modal-preview">
                                                @if ($service->image_path)
                                                    <img src="{{ mobile_content_url($service->image_path) }}"
                                                        alt="">
                                                @else
                                                    <div class="mc-modal-preview-empty"></div>
                                                @endif
                                                <div>
                                                    <label class="settings-reset mc-file-trigger">
                                                        Change Image
                                                        <input type="file" name="image" accept="image/*"
                                                            class="app-dropzone-input">
                                                    </label>
                                                    <span class="mc-file-name"></span>
                                                </div>
                                            </div>
                                            <p class="field-help">JPG or PNG (Max 2MB)</p>
                                        </div>
                                    </div>

                                    <div class="mc-modal-actions">
                                        <button type="button" class="settings-reset" data-modal-close>Cancel</button>
                                        <button type="submit" class="settings-save">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    @endforeach
                @endif

                <div class="mc-modal-overlay" id="add-service-modal">
                    <div class="mc-modal">
                        <div class="mc-modal-header">
                            <h3>Add Service</h3>
                            <button type="button" class="mc-modal-close" data-modal-close aria-label="Close">
                                <i data-lucide="x"></i>
                            </button>
                        </div>

                        <form method="POST"
                            action="{{ route('superadmin.settings.customer-content.services.store') }}"
                            enctype="multipart/form-data">
                            @csrf

                            <div class="mc-modal-body">
                                <div class="settings-field">
                                    <label>Title</label>
                                    <input type="text" name="title" maxlength="150"
                                        placeholder="e.g. Emergency Towing" required>
                                </div>
                                <div class="settings-field">
                                    <label>Category</label>
                                    <input type="text" name="category" maxlength="100" placeholder="e.g. Emergency">
                                </div>
                                <div class="settings-field">
                                    <label>Availability Note</label>
                                    <input type="text" name="availability_note" maxlength="255"
                                        placeholder="e.g. 24/7 · Fastest dispatch">
                                </div>
                                <div class="settings-field">
                                    <label>Description</label>
                                    <textarea name="description" maxlength="2000" placeholder="Informational description shown to customers" required></textarea>
                                </div>
                                <div class="settings-field">
                                    <label>Service Image</label>
                                    <input type="file" name="image" accept="image/*">
                                    <p class="field-help">JPG or PNG (Max 2MB)</p>
                                </div>
                            </div>

                            <div class="mc-modal-actions">
                                <button type="button" class="settings-reset" data-modal-close>Cancel</button>
                                <button type="submit" class="settings-save">Add Service</button>
                            </div>
                        </form>
                    </div>
                </div>
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
                                value="{{ old('mobile_support_phone', $settings['mobile_support_phone'] ?? '') }}"
                                required>
                        </div>
                        <div class="settings-field">
                            <label>Support Email</label>
                            <input type="email" name="mobile_support_email" maxlength="150"
                                value="{{ old('mobile_support_email', $settings['mobile_support_email'] ?? '') }}"
                                required>
                        </div>
                        <div class="settings-field">
                            <label>Office / Location</label>
                            <input type="text" name="mobile_support_location" maxlength="255"
                                value="{{ old('mobile_support_location', $settings['mobile_support_location'] ?? '') }}"
                                required>
                        </div>
                        <div class="settings-field">
                            <label>Operating Hours</label>
                            <input type="text" name="mobile_support_hours" maxlength="255"
                                placeholder="e.g. Available 24/7"
                                value="{{ old('mobile_support_hours', $settings['mobile_support_hours'] ?? '') }}"
                                required>
                        </div>
                    </div>
                    <div class="settings-actions">
                        <button type="submit" class="settings-save">Save Support Info</button>
                    </div>
                </form>
            </div>

            <div class="mc-section" id="mc-how-it-works">
                <div class="mc-section-intro mc-section-intro-with-action">
                    <div>
                        <h3>How It Works</h3>
                        <p>Manage the service steps shown to customers.</p>
                    </div>
                    <button type="button" class="settings-save" data-modal-target="add-step-modal">
                        <i data-lucide="plus"></i> Add Step
                    </button>
                </div>

                <h4 class="mc-subheading">Current Steps</h4>

                @if ($mobileHowItWorksSteps->isEmpty())
                    <p class="mc-empty">No steps yet.</p>
                @else
                    <div class="mc-list">
                        @foreach ($mobileHowItWorksSteps as $index => $step)
                            <div class="mc-service-row">
                                <span class="mc-step-number">{{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}</span>

                                <div class="mc-service-body">
                                    <div class="mc-service-top">
                                        <span class="mc-service-title">{{ $step->step_title }}</span>
                                        <span
                                            class="mc-status {{ $step->is_active ? 'is-active' : 'is-inactive' }}">{{ $step->is_active ? 'Active' : 'Inactive' }}</span>
                                    </div>
                                    <p class="mc-service-desc">{{ $step->step_description }}</p>
                                </div>

                                <div class="mc-service-actions">
                                    <div class="mc-order-buttons">
                                        <form method="POST"
                                            action="{{ route('superadmin.settings.customer-content.how-it-works.move', $step) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="direction" value="up">
                                            <button type="submit" class="mc-order-btn" title="Move up"
                                                @disabled($index === 0)>&uarr;</button>
                                        </form>
                                        <form method="POST"
                                            action="{{ route('superadmin.settings.customer-content.how-it-works.move', $step) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="direction" value="down">
                                            <button type="submit" class="mc-order-btn" title="Move down"
                                                @disabled($index === $mobileHowItWorksSteps->count() - 1)>&darr;</button>
                                        </form>
                                    </div>
                                    <button type="button" class="settings-reset"
                                        data-modal-target="edit-step-modal-{{ $step->id }}">Edit</button>
                                    <form method="POST"
                                        action="{{ route('superadmin.settings.customer-content.how-it-works.toggle', $step) }}"
                                        class="mc-toggle-form">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit"
                                            class="settings-reset">{{ $step->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @foreach ($mobileHowItWorksSteps as $step)
                        <div class="mc-modal-overlay" id="edit-step-modal-{{ $step->id }}">
                            <div class="mc-modal">
                                <div class="mc-modal-header">
                                    <h3>Edit Step</h3>
                                    <button type="button" class="mc-modal-close" data-modal-close
                                        aria-label="Close">
                                        <i data-lucide="x"></i>
                                    </button>
                                </div>

                                <form method="POST"
                                    action="{{ route('superadmin.settings.customer-content.how-it-works.update', $step) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="display_order" value="{{ $step->display_order }}">

                                    <div class="mc-modal-body">
                                        <div class="settings-field">
                                            <label>Step Title</label>
                                            <input type="text" name="step_title" value="{{ $step->step_title }}"
                                                maxlength="150" required>
                                        </div>
                                        <div class="settings-field">
                                            <label>Step Description</label>
                                            <textarea name="step_description" maxlength="2000" required>{{ $step->step_description }}</textarea>
                                        </div>
                                    </div>

                                    <div class="mc-modal-actions">
                                        <button type="button" class="settings-reset" data-modal-close>Cancel</button>
                                        <button type="submit" class="settings-save">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    @endforeach
                @endif

                <div class="mc-modal-overlay" id="add-step-modal">
                    <div class="mc-modal">
                        <div class="mc-modal-header">
                            <h3>Add Step</h3>
                            <button type="button" class="mc-modal-close" data-modal-close aria-label="Close">
                                <i data-lucide="x"></i>
                            </button>
                        </div>

                        <form method="POST"
                            action="{{ route('superadmin.settings.customer-content.how-it-works.store') }}">
                            @csrf

                            <div class="mc-modal-body">
                                <div class="settings-field">
                                    <label>Step Title</label>
                                    <input type="text" name="step_title" maxlength="150"
                                        placeholder="e.g. Request a Tow" required>
                                </div>
                                <div class="settings-field">
                                    <label>Step Description</label>
                                    <textarea name="step_description" maxlength="2000" placeholder="Explanatory text for this step" required></textarea>
                                </div>
                            </div>

                            <div class="mc-modal-actions">
                                <button type="button" class="settings-reset" data-modal-close>Cancel</button>
                                <button type="submit" class="settings-save">Add Step</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="mc-section" id="mc-coverage-areas">
                <div class="mc-section-intro mc-section-intro-with-action">
                    <div>
                        <h3>Coverage Areas</h3>
                        <p>Manage the service areas displayed in the Customer app.</p>
                    </div>
                    <button type="button" class="settings-save" data-modal-target="add-coverage-area-modal">
                        <i data-lucide="plus"></i> Add Coverage Area
                    </button>
                </div>

                <h4 class="mc-subheading">Current Areas</h4>

                @if ($mobileCoverageAreas->isEmpty())
                    <p class="mc-empty">No coverage areas yet.</p>
                @else
                    <div class="mc-list">
                        @foreach ($mobileCoverageAreas as $index => $area)
                            <div class="mc-service-row">
                                <span class="mc-step-number">{{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}</span>

                                <div class="mc-service-body">
                                    <div class="mc-service-top">
                                        <span class="mc-service-title">{{ $area->name }}</span>
                                        <span
                                            class="mc-status {{ $area->is_active ? 'is-active' : 'is-inactive' }}">{{ $area->is_active ? 'Active' : 'Inactive' }}</span>
                                    </div>
                                </div>

                                <div class="mc-service-actions">
                                    <div class="mc-order-buttons">
                                        <form method="POST"
                                            action="{{ route('superadmin.settings.customer-content.coverage-areas.move', $area) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="direction" value="up">
                                            <button type="submit" class="mc-order-btn" title="Move up"
                                                @disabled($index === 0)>&uarr;</button>
                                        </form>
                                        <form method="POST"
                                            action="{{ route('superadmin.settings.customer-content.coverage-areas.move', $area) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="direction" value="down">
                                            <button type="submit" class="mc-order-btn" title="Move down"
                                                @disabled($index === $mobileCoverageAreas->count() - 1)>&darr;</button>
                                        </form>
                                    </div>
                                    <button type="button" class="settings-reset"
                                        data-modal-target="edit-coverage-area-modal-{{ $area->id }}">Edit</button>
                                    <form method="POST"
                                        action="{{ route('superadmin.settings.customer-content.coverage-areas.toggle', $area) }}"
                                        class="mc-toggle-form">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit"
                                            class="settings-reset">{{ $area->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @foreach ($mobileCoverageAreas as $area)
                        <div class="mc-modal-overlay" id="edit-coverage-area-modal-{{ $area->id }}">
                            <div class="mc-modal">
                                <div class="mc-modal-header">
                                    <h3>Edit Coverage Area</h3>
                                    <button type="button" class="mc-modal-close" data-modal-close
                                        aria-label="Close">
                                        <i data-lucide="x"></i>
                                    </button>
                                </div>

                                <form method="POST"
                                    action="{{ route('superadmin.settings.customer-content.coverage-areas.update', $area) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="display_order" value="{{ $area->display_order }}">

                                    <div class="mc-modal-body">
                                        <div class="settings-field">
                                            <label>Name</label>
                                            <input type="text" name="name" value="{{ $area->name }}"
                                                maxlength="150" required>
                                        </div>
                                    </div>

                                    <div class="mc-modal-actions">
                                        <button type="button" class="settings-reset" data-modal-close>Cancel</button>
                                        <button type="submit" class="settings-save">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    @endforeach
                @endif

                <div class="mc-modal-overlay" id="add-coverage-area-modal">
                    <div class="mc-modal">
                        <div class="mc-modal-header">
                            <h3>Add Coverage Area</h3>
                            <button type="button" class="mc-modal-close" data-modal-close aria-label="Close">
                                <i data-lucide="x"></i>
                            </button>
                        </div>

                        <form method="POST"
                            action="{{ route('superadmin.settings.customer-content.coverage-areas.store') }}">
                            @csrf

                            <div class="mc-modal-body">
                                <div class="settings-field">
                                    <label>Name</label>
                                    <input type="text" name="name" maxlength="150" placeholder="e.g. Quezon City"
                                        required>
                                </div>
                            </div>

                            <div class="mc-modal-actions">
                                <button type="button" class="settings-reset" data-modal-close>Cancel</button>
                                <button type="submit" class="settings-save">Add Coverage Area</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="mc-section" id="mc-app-images">
                <div class="mc-section-intro">
                    <h3>App Images</h3>
                    <p>Photos shown on the Customer app's public Home and About pages.</p>
                </div>

                <form method="POST" action="{{ route('superadmin.settings.customer-content.images.update') }}"
                    enctype="multipart/form-data">
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
                    <p>Upload and manage the Android app version. The APK will be served from the download link on the
                        sign-in page.</p>
                </div>

                @if (session('apk_success'))
                    <p class="settings-feedback settings-feedback--success">{{ session('apk_success') }}</p>
                @endif

                <div class="mobile-app-summary">
                    <div class="mobile-app-summary-item">
                        <p class="mobile-app-summary-label">Current Version</p>
                        <p class="mobile-app-summary-value">{{ $androidApk['version_name'] ?: '—' }}</p>
                    </div>
                    <div class="mobile-app-summary-item">
                        <p class="mobile-app-summary-label">File Size</p>
                        <p class="mobile-app-summary-value">
                            {{ $androidApk['size_mb'] ? $androidApk['size_mb'] . ' MB' : '—' }}</p>
                    </div>
                    <div class="mobile-app-summary-item">
                        <p class="mobile-app-summary-label">Last Updated</p>
                        @if ($androidApk['uploaded_at'])
                            <p class="mobile-app-summary-value">
                                {{ \Illuminate\Support\Carbon::parse($androidApk['uploaded_at'])->format('M d, Y') }}</p>
                            <p class="mobile-app-summary-sub">
                                {{ \Illuminate\Support\Carbon::parse($androidApk['uploaded_at'])->format('g:i A') }}</p>
                        @else
                            <p class="mobile-app-summary-value">—</p>
                        @endif
                    </div>
                    <div class="mobile-app-summary-item">
                        <p class="mobile-app-summary-label">Status</p>
                        <form method="POST" action="{{ route('superadmin.settings.mobile-app.toggle') }}"
                            class="status-toggle-form">
                            @csrf
                            @method('PATCH')
                            <button type="submit"
                                class="status-toggle-btn {{ $androidAppActive ? 'is-active' : 'is-inactive' }}">
                                <span class="status-toggle-track"><span class="status-toggle-thumb"></span></span>
                                <span class="status-toggle-label">{{ $androidAppActive ? 'Active' : 'Inactive' }}</span>
                            </button>
                        </form>
                    </div>
                </div>

                @if ($androidApk['release_notes'])
                    <p class="field-help" style="margin-bottom: 16px;">{{ $androidApk['release_notes'] }}</p>
                @endif

                <form method="POST" action="{{ route('superadmin.settings.upload-apk') }}"
                    enctype="multipart/form-data">
                    @csrf

                    <div class="app-upload-grid">
                        <div class="app-upload-field">
                            <label class="app-upload-label" for="mobile_app_apk_file">Upload New APK</label>
                            <label for="mobile_app_apk_file" class="app-dropzone">
                                <i data-lucide="upload" class="app-dropzone-icon"></i>
                                <span class="app-dropzone-text" data-dropzone-text>Click to upload or drag and drop</span>
                                <span class="app-dropzone-sub" data-dropzone-sub>APK file (Max 100MB)</span>
                            </label>
                            <input type="file" id="mobile_app_apk_file" name="apk_file" accept=".apk"
                                class="app-dropzone-input" data-dropzone-input required>
                            @error('apk_file')
                                <small class="error-text">{{ $message }}</small>
                            @enderror

                            <div class="settings-field app-version-field">
                                <label for="mobile_app_version_name">Version Name</label>
                                <input type="text" id="mobile_app_version_name" name="version_name" maxlength="50"
                                    placeholder="e.g. 1.4.0" value="{{ old('version_name') }}">
                            </div>
                        </div>

                        <div class="app-upload-field">
                            <label class="app-upload-label" for="mobile_app_release_notes">Release Notes</label>
                            <textarea id="mobile_app_release_notes" name="release_notes" maxlength="2000"
                                placeholder="What's new in this version?" class="app-release-notes" data-counter-max="2000">{{ old('release_notes') }}</textarea>
                            <span class="char-counter" data-counter-output>0/2000</span>
                        </div>
                    </div>

                    <div class="app-notice">
                        <i data-lucide="info"></i>
                        <span>Version Name is set manually with each upload.</span>
                    </div>

                    <div class="settings-actions app-upload-actions">
                        <button type="submit" class="settings-save">Upload Android App</button>
                    </div>
                </form>
            </div>

            <hr class="settings-divider">

            <div class="settings-section">
                <div class="settings-section-head">
                    <h3>iOS App</h3>
                    <p>Upload and manage the iOS app version. The IPA will be served from the download link on the sign-in
                        page.</p>
                </div>

                <div class="mobile-app-summary">
                    <div class="mobile-app-summary-item">
                        <p class="mobile-app-summary-label">Current Version</p>
                        <p class="mobile-app-summary-value">—</p>
                    </div>
                    <div class="mobile-app-summary-item">
                        <p class="mobile-app-summary-label">File Size</p>
                        <p class="mobile-app-summary-value">—</p>
                    </div>
                    <div class="mobile-app-summary-item">
                        <p class="mobile-app-summary-label">Last Updated</p>
                        <p class="mobile-app-summary-value">—</p>
                    </div>
                    <div class="mobile-app-summary-item">
                        <p class="mobile-app-summary-label">Status</p>
                        <span class="app-status-static">Inactive</span>
                    </div>
                </div>

                <div class="app-upload-grid">
                    <div class="app-upload-field">
                        <label class="app-upload-label">Upload New IPA</label>
                        <div class="app-dropzone app-dropzone-disabled">
                            <i data-lucide="upload" class="app-dropzone-icon"></i>
                            <span class="app-dropzone-text">Click to upload or drag and drop</span>
                            <span class="app-dropzone-sub">IPA file (Max 100MB)</span>
                        </div>
                    </div>

                    <div class="app-upload-field">
                        <label class="app-upload-label">Release Notes</label>
                        <textarea class="app-release-notes" placeholder="What's new in this version?" disabled></textarea>
                        <span class="char-counter">0/2000</span>
                    </div>
                </div>

                <div class="app-notice">
                    <i data-lucide="info"></i>
                    <span>iOS distribution isn't set up yet. This section shows the planned layout only.</span>
                </div>

                <div class="settings-actions app-upload-actions">
                    <button type="button" class="settings-save app-btn-disabled" disabled>Upload iOS App</button>
                </div>
            </div>

            <hr class="settings-divider">

            <div class="settings-section">
                <div class="settings-section-head">
                    <h3>Download QR Code</h3>
                    <p>Generate a QR code that links to your app download page. Customers can scan this code to easily
                        access the TowMate app.</p>
                </div>

                <div class="qr-card">
                    <div class="qr-left">
                        <label for="mobile_app_download_url">Download page URL</label>
                        <div class="qr-url-row">
                            <input type="text" id="mobile_app_download_url" value="{{ $appDownloadUrl }}" readonly>
                            <button type="button" class="qr-copy-btn" id="copyAppUrlBtn" title="Copy URL"><i
                                    data-lucide="copy"></i></button>
                        </div>
                        <p class="field-help">This URL will open your app download page (Android/iOS).</p>
                    </div>

                    <div class="qr-divider"></div>

                    <div class="qr-right">
                        <div class="qr-preview-box">
                            <canvas id="appQrCanvas"
                                data-endpoint="{{ route('superadmin.settings.mobile-app.qr-code') }}"></canvas>
                        </div>
                        <div class="qr-meta">
                            <h4>QR Code</h4>
                            <p>Scan with your phone camera to open the app download page.</p>
                            <div class="qr-actions">
                                <button type="button" id="generateQrBtn" class="qr-btn qr-btn-dark"><i
                                        data-lucide="refresh-cw"></i> Generate QR Code</button>
                                <button type="button" id="downloadQrBtn" class="qr-btn qr-btn-outline"><i
                                        data-lucide="download"></i> Download QR Code</button>
                            </div>
                            <small class="error-text" id="qrGenerateError" style="display: none;"></small>
                        </div>
                    </div>
                </div>
            </div>

            <hr class="settings-divider">

            <div class="app-versioning">
                <div class="app-versioning-icon"><i data-lucide="lightbulb"></i></div>
                <div>
                    <h4>About Versioning</h4>
                    <p>Version Name is entered manually with each APK/IPA upload. Automatic version incrementing isn't enabled yet.</p>
                </div>
            </div>
        </div>

    </div>

    <script>
        const tabs = document.querySelectorAll(".settings-tab");
        const contents = document.querySelectorAll(".settings-content");
        const mcSubnavButtons = document.querySelectorAll(".mc-subnav-btn");
        const mcSections = document.querySelectorAll(".mc-section");

        function activateTab(tabName) {
            const tab = [...tabs].find(t => t.dataset.tab === tabName);
            const content = document.getElementById(tabName);

            if (!tab || !content) {
                return false;
            }

            tabs.forEach(t => t.classList.remove("active"));
            tab.classList.add("active");

            contents.forEach(c => c.classList.remove("active"));
            content.classList.add("active");

            return true;
        }

        function activateSubSection(sectionId) {
            const btn = [...mcSubnavButtons].find(b => b.dataset.mcSection === sectionId);
            const section = document.getElementById(sectionId);

            if (!btn || !section) {
                return false;
            }

            mcSubnavButtons.forEach(b => b.classList.remove("active"));
            btn.classList.add("active");

            mcSections.forEach(s => s.classList.remove("active"));
            section.classList.add("active");

            return true;
        }

        function syncTabStateToUrl() {
            const activeTab = document.querySelector(".settings-tab.active")?.dataset.tab;
            const params = new URLSearchParams();

            if (activeTab) {
                params.set("tab", activeTab);
            }

            if (activeTab === "customer-content") {
                const activeSub = document.querySelector(".mc-subnav-btn.active")?.dataset.mcSection;

                if (activeSub) {
                    params.set("sub", activeSub);
                }
            }

            const query = params.toString();
            history.replaceState(null, "", location.pathname + (query ? "?" + query : ""));
        }

        tabs.forEach(tab => {
            tab.addEventListener("click", () => {
                activateTab(tab.dataset.tab);
                syncTabStateToUrl();
            });
        });

        mcSubnavButtons.forEach(btn => {
            btn.addEventListener("click", () => {
                activateSubSection(btn.dataset.mcSection);
                syncTabStateToUrl();
            });
        });

        const urlParams = new URLSearchParams(location.search);
        const requestedTab = urlParams.get("tab");
        const requestedSub = urlParams.get("sub");

        if (requestedTab && activateTab(requestedTab) && requestedTab === "customer-content" && requestedSub) {
            activateSubSection(requestedSub);
        }

        function openMcModal(modal) {
            modal?.classList.add("is-open");
        }

        function closeMcModal(modal) {
            modal?.classList.remove("is-open");
        }

        document.querySelectorAll("[data-modal-target]").forEach(trigger => {
            trigger.addEventListener("click", () => {
                openMcModal(document.getElementById(trigger.dataset.modalTarget));
            });
        });

        document.querySelectorAll("[data-modal-close]").forEach(closer => {
            closer.addEventListener("click", () => {
                closeMcModal(closer.closest(".mc-modal-overlay"));
            });
        });

        document.querySelectorAll(".mc-modal-overlay").forEach(overlay => {
            overlay.addEventListener("click", event => {
                if (event.target === overlay) {
                    closeMcModal(overlay);
                }
            });
        });

        document.addEventListener("keydown", event => {
            if (event.key === "Escape") {
                document.querySelectorAll(".mc-modal-overlay.is-open").forEach(closeMcModal);
            }
        });

        document.addEventListener("change", event => {
            if (event.target.matches(".mc-file-trigger input[type=\"file\"]")) {
                const nameEl = event.target.closest(".mc-modal-preview")?.querySelector(".mc-file-name");
                if (nameEl) {
                    nameEl.textContent = event.target.files[0] ? event.target.files[0].name : "";
                }
            }
        });
    </script>
@endsection

@push('scripts')
    <script>
        (function() {
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

                fetch(qrEndpoint, {
                        cache: 'no-store'
                    })
                    .then(function(response) {
                        if (!response.ok) {
                            throw new Error('QR request failed with status ' + response.status);
                        }
                        return response.blob();
                    })
                    .then(function(blob) {
                        return new Promise(function(resolve, reject) {
                            const image = new Image();
                            const objectUrl = URL.createObjectURL(blob);

                            image.onload = function() {
                                canvas.width = image.naturalWidth;
                                canvas.height = image.naturalHeight;
                                ctx.clearRect(0, 0, canvas.width, canvas.height);
                                ctx.drawImage(image, 0, 0);
                                URL.revokeObjectURL(objectUrl);
                                resolve();
                            };

                            image.onerror = function() {
                                URL.revokeObjectURL(objectUrl);
                                reject(new Error('Could not decode the QR image.'));
                            };

                            image.src = objectUrl;
                        });
                    })
                    .catch(function() {
                        showQrError('Could not generate the QR code. Please try again.');
                    });
            }

            renderQr();

            generateBtn?.addEventListener('click', renderQr);

            downloadBtn?.addEventListener('click', function() {
                if (!canvas || !canvas.width) {
                    showQrError('Generate the QR code before downloading.');
                    return;
                }

                const link = document.createElement('a');
                link.download = 'towmate-app-qr.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
            });

            copyBtn?.addEventListener('click', async function() {
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

    <script>
        (function() {
            const apkInput = document.querySelector('[data-dropzone-input]');
            const dropzoneText = document.querySelector('[data-dropzone-text]');
            const dropzoneSub = document.querySelector('[data-dropzone-sub]');
            const defaultText = dropzoneText ? dropzoneText.textContent : '';
            const defaultSub = dropzoneSub ? dropzoneSub.textContent : '';

            apkInput?.addEventListener('change', function() {
                if (apkInput.files && apkInput.files[0]) {
                    dropzoneText.textContent = apkInput.files[0].name;
                    dropzoneSub.textContent = 'Click to choose a different file';
                } else {
                    dropzoneText.textContent = defaultText;
                    dropzoneSub.textContent = defaultSub;
                }
            });

            const counterInput = document.querySelector('[data-counter-max]');
            const counterOutput = document.querySelector('[data-counter-output]');

            if (counterInput && counterOutput) {
                const maxLength = counterInput.dataset.counterMax;

                const updateCounter = () => {
                    counterOutput.textContent = counterInput.value.length + '/' + maxLength;
                };

                counterInput.addEventListener('input', updateCounter);
                updateCounter();
            }
        })();
    </script>
@endpush
