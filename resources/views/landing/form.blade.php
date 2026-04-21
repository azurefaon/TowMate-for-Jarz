@extends('landing.layouts.app')

@section('content')
    {{-- All zone selection and related JS removed for clean customer form --}}
    @push('styles')
        <link rel="stylesheet" href="{{ asset('home_page/css/landing.css') }}">
        <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
        <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
        <style>
            .booking-page-nav-neutral .landing-nav {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
            }

            .booking-page-nav-neutral .brand-lockup {
                display: inline-flex;
                align-items: center;
                gap: 12px;
                text-decoration: none;
                color: inherit;
            }

            .booking-page-nav-neutral .brand-lockup img {
                width: 44px;
                height: 44px;
                object-fit: contain;
            }

            .booking-page-nav-neutral .logo {
                margin: 0;
                letter-spacing: 0.08em;
            }

            .booking-page-nav-neutral .nav-home-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 10px 16px;
                border-radius: 999px;
                background: #111827;
                color: #fff;
                text-decoration: none;
                font-weight: 600;
                transition: opacity 0.2s ease;
            }

            .booking-page-nav-neutral .nav-home-btn:hover {
                opacity: 0.9;
            }

            .booking-page-nav-neutral .menu-toggle {
                display: none;
            }
        </style>
    @endpush

    <div class="landing-wrapper booking-page-nav-neutral">

        <nav class="landing-nav">
            <a href="{{ route('landing') }}" class="brand-lockup" aria-label="Jarz home">
                <img src="{{ asset('admin/images/logo.png') }}" alt="Jarz logo">
                <h2 class="logo">JARZ</h2>
            </a>

            <a href="{{ route('landing') }}" class="nav-home-btn">
                <i class='bx bx-arrow-back'></i>
                <span>Back to Home</span>
            </a>
        </nav>

        <!-- BOOKING FORM -->
        <section class="section" id="booking">
            <div class="booking-container">
                <div class="booking-header">
                    <h2>Book Your Towing Service</h2>
                    <p class="booking-sub">
                        A cleaner booking flow helps customers finish faster: enter your details, pin the pickup,
                        review the route, and confirm the request with confidence.
                    </p>
                    {{-- 
                    <div class="booking-flow-strip">
                        <div class="booking-flow-pill is-active"><span>1</span>Customer info</div>
                        <div class="booking-flow-pill"><span>2</span>Pickup pin</div>
                        <div class="booking-flow-pill"><span>3</span>Vehicle and route</div>
                        <div class="booking-flow-pill"><span>4</span>Review and confirm</div>
                    </div> --}}
                </div>

                <div class="booking-content booking-content-single">
                    <div class="booking-form-container booking-form-container-wide">
                        <form id="bookingForm" class="booking-form" action="{{ route('landing.book.store') }}"
                            method="POST" enctype="multipart/form-data">
                            @csrf


                            <div class="form-section">
                                <h3>Quick Customer Details</h3>
                                <div class="form-row form-row-three">
                                    <div class="form-group">
                                        <label for="first_name">First Name *</label>
                                        <input type="text" id="first_name" name="first_name" required
                                            value="{{ old('first_name') }}">
                                        @error('first_name')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    <div class="form-group">
                                        <label for="middle_name">Middle Name</label>
                                        <input type="text" id="middle_name" name="middle_name"
                                            value="{{ old('middle_name') }}">
                                        @error('middle_name')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    <div class="form-group">
                                        <label for="last_name">Last Name *</label>
                                        <input type="text" id="last_name" name="last_name" required
                                            value="{{ old('last_name') }}">
                                        @error('last_name')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="age">Age *</label>
                                        <input type="number" id="age" name="age" min="1" max="120"
                                            required value="{{ old('age') }}">
                                        @error('age')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    <div class="form-group">
                                        <label for="customer_type">Customer Type *</label>
                                        <select id="customer_type" name="customer_type" required>
                                            <option value="regular"
                                                {{ old('customer_type', 'regular') === 'regular' ? 'selected' : '' }}>
                                                Regular</option>
                                            <option value="pwd" {{ old('customer_type') === 'pwd' ? 'selected' : '' }}>
                                                PWD</option>
                                            <option value="senior"
                                                {{ old('customer_type') === 'senior' ? 'selected' : '' }}>Senior</option>
                                        </select>
                                        @error('customer_type')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="phone">Phone Number *</label>
                                        <input type="tel" id="phone" name="phone" placeholder="09123456789"
                                            required value="{{ old('phone') }}">
                                        @error('phone')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    <div class="form-group">
                                        <label for="email">Email Address</label>
                                        <input type="email" id="email" name="email" placeholder="yourname@gmail.com"
                                            value="{{ old('email') }}">
                                        @error('email')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <input type="hidden" name="confirmation_type" value="system">
                                <input type="hidden" name="schedule_fallback_accepted" value="0">

                                <div class="form-section compact-section">
                                    <h3>Booking Mode</h3>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="service_type">Booking Mode *</label>
                                            <select id="service_type" name="service_type" required>
                                                <option value="book_now"
                                                    {{ old('service_type', 'book_now') === 'book_now' ? 'selected' : '' }}>
                                                    Book Now</option>
                                                <option value="schedule"
                                                    {{ old('service_type') === 'schedule' ? 'selected' : '' }}>Schedule
                                                    Later</option>
                                            </select>
                                            @error('service_type')
                                                <span class="error-message">{{ $message }}</span>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="form-row" id="scheduleFields"
                                        style="display: {{ old('service_type') === 'schedule' ? 'grid' : 'none' }};">
                                        <div class="form-group">
                                            <label for="scheduled_date">Preferred Date</label>
                                            <input type="date" id="scheduled_date" name="scheduled_date"
                                                min="{{ now()->toDateString() }}" value="{{ old('scheduled_date') }}">
                                            @error('scheduled_date')
                                                <span class="error-message">{{ $message }}</span>
                                            @enderror
                                        </div>
                                        <div class="form-group">
                                            <label for="scheduled_time">Preferred Time</label>
                                            <input type="time" id="scheduled_time" name="scheduled_time"
                                                value="{{ old('scheduled_time') }}">
                                            @error('scheduled_time')
                                                <span class="error-message">{{ $message }}</span>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h3>Service Details</h3>

                                <div class="form-group route-preview-panel">
                                    <div class="location-quick-head">
                                        <h4>Live Route Preview</h4>
                                        <p>Your confirmed pickup and dropoff pins will appear here.</p>
                                    </div>
                                    <div
                                        style="margin-top: 10px; border: 1px solid #e2e8f0; border-radius: 18px; overflow: hidden; height: 320px; background: #f8fafc;">
                                        <div id="map" style="width: 100%; height: 100%;"></div>
                                    </div>
                                </div>


                                <div class="form-row form-row-location">
                                    <div class="form-group">
                                        <label for="pickup_address">Pickup Location or Landmark *</label>
                                        <div class="input-map-wrapper">
                                            <input type="text" id="pickup_address" name="pickup_address"
                                                placeholder="Where should we pick you up?" required
                                                value="{{ old('pickup_address') }}">
                                            <div id="pickupSuggestions" class="suggestions"></div>
                                        </div>
                                        <input type="hidden" id="pickup_lat" name="pickup_lat"
                                            value="{{ old('pickup_lat') }}">
                                        <input type="hidden" id="pickup_lng" name="pickup_lng"
                                            value="{{ old('pickup_lng') }}">
                                        <input type="hidden" id="pickupConfirmedInput" name="pickup_confirmed"
                                            value="{{ old('pickup_confirmed', 0) }}">
                                        <input type="hidden" id="dropoffConfirmedInput" name="dropoff_confirmed"
                                            value="{{ old('dropoff_confirmed', 0) }}">
                                        @error('pickup_address')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>

                                    <div class="form-group">
                                        <label for="dropoff_address">Drop-off Location or Landmark *</label>
                                        <div class="input-map-wrapper">
                                            <input type="text" id="dropoff_address" name="dropoff_address"
                                                placeholder="Where are you headed?" required
                                                value="{{ old('dropoff_address') }}">
                                            <div id="dropSuggestions" class="suggestions"></div>
                                        </div>
                                        <input type="hidden" id="drop_lat" name="drop_lat"
                                            value="{{ old('drop_lat') }}">
                                        <input type="hidden" id="drop_lng" name="drop_lng"
                                            value="{{ old('drop_lng') }}">
                                        @error('dropoff_address')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="truck_type_id">Vehicle Type *</label>
                                        <select id="truck_type_id" name="truck_type_id" required>
                                            <option value="">Select vehicle type</option>
                                            @foreach ($truckTypes as $type)
                                                @php $isUnavailable = ($type->status ?? 'active') !== 'active'; @endphp
                                                <option value="{{ $type->id }}"
                                                    {{ (string) old('truck_type_id') === (string) $type->id ? 'selected' : '' }}
                                                    @disabled($isUnavailable)>
                                                    {{ $type->name }}{{ $isUnavailable ? ' (Unavailable)' : '' }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <small style="color:#6b7280; display:block; margin-top:6px;">Gray vehicle types
                                            are currently unavailable and cannot be selected.</small>
                                        @error('truck_type_id')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>

                                    <div class="form-group">
                                        <label for="vehicle_category">Customer Vehicle Category *</label>
                                        <select id="vehicle_category" name="vehicle_category" required>
                                            <option value="">Select vehicle category</option>
                                            <option value="2_wheeler"
                                                {{ old('vehicle_category') === '2_wheeler' ? 'selected' : '' }}>2
                                                Wheeler</option>
                                            <option value="3_wheeler"
                                                {{ old('vehicle_category') === '3_wheeler' ? 'selected' : '' }}>3
                                                Wheeler</option>
                                            <option value="4_wheeler"
                                                {{ old('vehicle_category') === '4_wheeler' ? 'selected' : '' }}>4
                                                Wheeler</option>
                                            <option value="heavy_vehicle"
                                                {{ in_array(old('vehicle_category'), ['heavy_vehicle', '6_wheeler', '10_wheeler'], true) ? 'selected' : '' }}>
                                                Heavy Vehicle (6+ Wheels)</option>
                                            <option value="other"
                                                {{ old('vehicle_category') === 'other' ? 'selected' : '' }}>
                                                Other
                                            </option>
                                        </select>
                                    </div>
                                </div>


                            </div>

                            <div class="form-section compact-section">
                                <h3>Extra Notes</h3>

                                <div class="form-group">
                                    <label for="vehicle_image">Vehicle Image</label>
                                    <input type="file" id="vehicle_image" name="vehicle_image"
                                        accept=".jpg,.jpeg,.png">
                                    @error('vehicle_image')
                                        <span class="error-message">{{ $message }}</span>
                                    @enderror
                                </div>


                                <div class="form-group">
                                    <label for="notes">Special Notes</label>
                                    <textarea id="notes" name="notes" rows="3" placeholder="Any special instructions or notes...">{{ old('notes') }}</textarea>
                                    @error('notes')
                                        <span class="error-message">{{ $message }}</span>
                                    @enderror
                                </div>


                            </div>

                            <div class="form-actions">
                                <button type="button" class="btn-secondary" onclick="history.back()">
                                    <i class='bx bx-arrow-back'></i>
                                    Back
                                </button>
                                <button type="button" class="btn-primary" id="submitBookingBtn">
                                    <span>Book Now</span>
                                    <i class='bx bx-check-circle'></i>
                                </button>
                                <button type="button" class="btn-primary" id="scheduleBookingBtn"
                                    style="display:none;">
                                    <span>Schedule Booking</span>
                                    <i class='bx bx-calendar'></i>
                                </button>
                            </div>
                        </form>


                        <div class="modal-overlay" id="confirmationModal">
                            <div class="modal-dialog">
                                <h3 id="bookingSummaryTitle">Book Now</h3>
                                <div class="modal-body" id="bookingSummary"></div>
                                <div class="modal-actions">
                                    <button type="button" class="btn-secondary" id="editBookingBtn">Back</button>
                                    <button type="button" class="btn-primary" id="confirmBookingBtn">Confirm Book
                                        Now</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

    </div>

    @push('scripts')
        <script src="{{ asset('home_page/js/landing.js') }}"></script>
        {{-- Zone selection JS removed --}}
        {{-- Google Maps is disabled for now while Leaflet is active.
        <script
            src="https://maps.googleapis.com/maps/api/js?key={{ urlencode(config('services.google_maps.key')) }}&libraries=places"
            defer></script>
        --}}
        <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
        <script>
            window.bookingGeoConfig = {
                searchUrl: @json(route('geo.search')),
                reverseUrl: @json(route('geo.reverse')),
                routeUrl: @json(route('geo.route')),
                pricingPreviewUrl: @json(route('geo.pricing.preview')),
                csrfToken: @json(csrf_token()),
            };
        </script>
        @php
            $truckRates = $truckTypes
                ->mapWithKeys(function ($type) {
                    return [
                        $type->id => [
                            'base' => (float) $type->base_rate,
                            'perKm' => (float) $type->per_km_rate,
                        ],
                    ];
                })
                ->toArray();
        @endphp
        <script>
            const truckRates = {!! json_encode($truckRates, JSON_UNESCAPED_UNICODE) !!};

            const bookingForm = document.querySelector('.booking-form');
            const confirmationModal = document.getElementById('confirmationModal');
            const bookingSummary = document.getElementById('bookingSummary');
            const confirmBookingBtn = document.getElementById('confirmBookingBtn');
            const editBookingBtn = document.getElementById('editBookingBtn');

            let isConfirmed = false;

            const formFields = bookingForm.querySelectorAll('input, select, textarea');

            formFields.forEach(function(field) {
                const eventName = field.type === 'file' || field.tagName === 'SELECT' ? 'change' : 'input';

                field.addEventListener(eventName, function() {
                    resetConfirmationState();
                    clearFieldError(field);
                });
            });

            function getPrimaryActionLabel() {
                return serviceTypeInput?.value === 'schedule' ? 'Schedule Booking' : 'Book Now';
            }

            function getConfirmationActionLabel() {
                return serviceTypeInput?.value === 'schedule' ? 'Confirm Scheduled Booking' : 'Confirm Book Now';
            }

            function applyBookingActionLabels() {
                if (submitBookingBtn) {
                    const labelTarget = submitBookingBtn.querySelector('span') || submitBookingBtn;
                    labelTarget.textContent = getPrimaryActionLabel();
                }

                if (confirmBookingBtn) {
                    confirmBookingBtn.textContent = getConfirmationActionLabel();
                    confirmBookingBtn.disabled = false;
                }

                const bookingSummaryTitle = document.getElementById('bookingSummaryTitle');
                if (bookingSummaryTitle) {
                    bookingSummaryTitle.textContent = getPrimaryActionLabel();
                }
            }

            function setSubmitBookingState(isBusy) {
                if (!submitBookingBtn) {
                    return;
                }

                const labelTarget = submitBookingBtn.querySelector('span') || submitBookingBtn;

                if (isBusy) {
                    submitBookingBtn.disabled = true;
                    submitBookingBtn.classList.add('disabled');
                    submitBookingBtn.setAttribute('aria-busy', 'true');
                    labelTarget.textContent = 'Preparing...';
                    return;
                }

                submitBookingBtn.removeAttribute('aria-busy');
                labelTarget.textContent = getPrimaryActionLabel();

                if (typeof toggleBookBtn === 'function') {
                    toggleBookBtn();
                    return;
                }

                submitBookingBtn.disabled = false;
                submitBookingBtn.classList.remove('disabled');
            }

            function resetConfirmationState() {
                isConfirmed = false;

                if (confirmationModal) {
                    confirmationModal.classList.remove('modal-open');
                }

                applyBookingActionLabels();
            }

            function ensureErrorElement(input) {
                const container = input.closest('.form-group');

                if (!container) {
                    return null;
                }

                let errorElement = container.querySelector('.client-error-message');

                if (!errorElement) {
                    errorElement = document.createElement('span');
                    errorElement.className = 'error-message client-error-message';
                    container.appendChild(errorElement);
                }

                return errorElement;
            }

            function setFieldError(input, message) {
                if (!input) {
                    return;
                }

                input.classList.add('input-error');
                input.setAttribute('aria-invalid', 'true');
                input.setCustomValidity(message);

                const errorElement = ensureErrorElement(input);
                if (errorElement) {
                    errorElement.textContent = message;
                }
            }

            function clearFieldError(input) {
                if (!input) {
                    return;
                }

                input.classList.remove('input-error');
                input.removeAttribute('aria-invalid');
                input.setCustomValidity('');

                const container = input.closest('.form-group');
                const errorElement = container ? container.querySelector('.client-error-message') : null;

                if (errorElement) {
                    errorElement.textContent = '';
                }
            }

            window.showBookingFieldError = setFieldError;
            window.clearBookingFieldError = clearFieldError;

            bookingForm.addEventListener('submit', async function(event) {
                if (!isConfirmed) {
                    event.preventDefault();

                    if (!validateBookingForm()) {
                        return;
                    }

                    if (typeof ensureDispatchAvailabilityForBooking === 'function' && !(
                            await ensureDispatchAvailabilityForBooking())) {
                        return;
                    }

                    showConfirmationSummary();
                }
            });

            function validateBookingForm() {
                const requiredFields = ['first_name', 'last_name', 'age', 'phone', 'truck_type_id', 'vehicle_category',
                    'pickup_address',
                    'dropoff_address', 'customer_type', 'service_type'
                ];
                let valid = true;
                let firstInvalidField = null;

                requiredFields.forEach(function(field) {
                    const input = document.getElementById(field);

                    if (!input) {
                        return;
                    }

                    clearFieldError(input);

                    if (!input.value || input.value.trim() === '') {
                        setFieldError(input, 'This field is required.');
                        firstInvalidField = firstInvalidField || input;
                        valid = false;
                    }
                });

                const serviceTypeInput = document.getElementById('service_type');
                const scheduleDateInput = document.getElementById('scheduled_date');
                const scheduleTimeInput = document.getElementById('scheduled_time');

                if (serviceTypeInput && serviceTypeInput.value === 'schedule') {
                    if (!scheduleDateInput.value) {
                        setFieldError(scheduleDateInput, 'Please choose a preferred date.');
                        firstInvalidField = firstInvalidField || scheduleDateInput;
                        valid = false;
                    }

                    if (!scheduleTimeInput.value) {
                        setFieldError(scheduleTimeInput, 'Please choose a preferred time.');
                        firstInvalidField = firstInvalidField || scheduleTimeInput;
                        valid = false;
                    }
                }

                const phoneInput = document.getElementById('phone');
                const rawPhone = phoneInput.value.trim();
                if (/^9\d{9}$/.test(rawPhone)) {
                    phoneInput.value = '0' + rawPhone;
                }

                const phoneRegex = /^(09\d{9}|\+639\d{9}|9\d{9})$/;
                if (phoneInput.value && !phoneRegex.test(phoneInput.value)) {
                    setFieldError(phoneInput, 'Please enter a valid Philippine phone number.');
                    firstInvalidField = firstInvalidField || phoneInput;
                    valid = false;
                }

                const emailInput = document.getElementById('email');
                const emailRegex =
                    /^[^\s@]+@(gmail\.com|yahoo\.com|ymail\.com|outlook\.com|hotmail\.com|live\.com|icloud\.com|aol\.com|gmx\.com|proton\.me|protonmail\.com|example\.com)$/i;
                if (emailInput.value && !emailRegex.test(emailInput.value)) {
                    setFieldError(emailInput,
                        'Email must be valid and able to receive system notifications and receipts.');
                    firstInvalidField = firstInvalidField || emailInput;
                    valid = false;
                }

                const vehicleImageInput = document.getElementById('vehicle_image');
                const file = vehicleImageInput?.files?.[0];
                const allowedTypes = ['image/jpeg', 'image/png'];
                if (file && !allowedTypes.includes(file.type)) {
                    setFieldError(vehicleImageInput, 'Vehicle image must be a JPG or PNG file only.');
                    firstInvalidField = firstInvalidField || vehicleImageInput;
                    valid = false;
                }

                const pickupInput = document.getElementById('pickup_address');
                const dropoffInput = document.getElementById('dropoff_address');
                const pickupLatInput = document.getElementById('pickup_lat');
                const pickupLngInput = document.getElementById('pickup_lng');
                const dropLatInput = document.getElementById('drop_lat');
                const dropLngInput = document.getElementById('drop_lng');
                if (!pickupLatInput?.value || !pickupLngInput?.value) {
                    setFieldError(pickupInput, 'Please choose the pickup address from the suggestions or the map.');
                    firstInvalidField = firstInvalidField || pickupInput;
                    valid = false;
                }

                if (!dropLatInput?.value || !dropLngInput?.value) {
                    setFieldError(dropoffInput, 'Please choose the dropoff address from the suggestions or the map.');
                    firstInvalidField = firstInvalidField || dropoffInput;
                    valid = false;
                }

                if (!valid && firstInvalidField) {
                    firstInvalidField.focus();
                    firstInvalidField.reportValidity();
                }

                return valid;
            }

            const serviceTypeInput = document.getElementById('service_type');
            const scheduleFields = document.getElementById('scheduleFields');
            const scheduledDateInput = document.getElementById('scheduled_date');
            const scheduledTimeInput = document.getElementById('scheduled_time');
            const submitBookingBtn = document.getElementById('submitBookingBtn');

            function toggleScheduleFields() {
                if (!serviceTypeInput || !scheduleFields) {
                    return;
                }

                const isScheduled = serviceTypeInput.value === 'schedule';
                scheduleFields.style.display = isScheduled ? 'grid' : 'none';

                if (scheduledDateInput) {
                    scheduledDateInput.required = isScheduled;
                    scheduledDateInput.disabled = !isScheduled;
                }

                if (scheduledTimeInput) {
                    scheduledTimeInput.required = isScheduled;
                    scheduledTimeInput.disabled = !isScheduled;
                }

                applyBookingActionLabels();
                resetConfirmationState();
            }

            toggleScheduleFields();
            applyBookingActionLabels();
            serviceTypeInput?.addEventListener('change', toggleScheduleFields);

            submitBookingBtn?.addEventListener('click', async function() {
                setSubmitBookingState(true);

                try {
                    if (!validateBookingForm()) {
                        return;
                    }

                    if (typeof ensureDispatchAvailabilityForBooking === 'function' && !(
                            await ensureDispatchAvailabilityForBooking())) {
                        return;
                    }

                    showConfirmationSummary();
                } finally {
                    setSubmitBookingState(false);
                }
            });

            function escapeSummaryValue(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function renderSummarySection(title, items, options = {}) {
                const rows = items
                    .filter((item) => item && String(item.value ?? '').trim() !== '')
                    .map((item) => `
                        <div class="summary-row${item.wide ? ' summary-row-wide' : ''}">
                            <span>${escapeSummaryValue(item.label)}</span>
                            <strong>${escapeSummaryValue(item.value)}</strong>
                        </div>
                    `)
                    .join('');

                const totalMarkup = options.totalValue ? `
                    <div class="summary-total">
                        <span>${escapeSummaryValue(options.totalLabel || 'Estimated Total')}</span>
                        <h2>${escapeSummaryValue(options.totalValue)}</h2>
                    </div>
                ` : '';

                const helperMarkup = options.helperNote ?
                    `<p class="summary-helper-note">${escapeSummaryValue(options.helperNote)}</p>` : '';

                return `
                    <div class="summary-section">
                        <div class="summary-section-title">${escapeSummaryValue(title)}</div>
                        <div class="summary-grid">
                            ${rows}
                            ${totalMarkup}
                        </div>
                        ${helperMarkup}
                    </div>
                `;
            }

            function showConfirmationSummary() {
                const pickup = document.getElementById('pickup_address').value;
                const dropoff = document.getElementById('dropoff_address').value;
                const vehicleSelect = document.getElementById('truck_type_id');
                const vehicle = vehicleSelect.options[vehicleSelect.selectedIndex]?.text || 'Not selected';
                const phone = document.getElementById('phone').value;
                const email = document.getElementById('email').value;
                const age = document.getElementById('age').value;
                const notes = document.getElementById('notes').value;
                const serviceType = serviceTypeInput?.value === 'schedule' ? 'Scheduled Later' : 'Book Now';
                const scheduleText = serviceTypeInput?.value === 'schedule' ?
                    `${scheduledDateInput?.value || 'N/A'} ${scheduledTimeInput?.value || ''}`.trim() : 'Immediate dispatch';
                const vehicleCategorySelect = document.getElementById('vehicle_category');
                const vehicleCategory = vehicleCategorySelect?.options[vehicleCategorySelect.selectedIndex]?.text || '';
                const customerTypeSelect = document.getElementById('customer_type');
                const customerType = customerTypeSelect?.options[customerTypeSelect.selectedIndex]?.text || 'Regular';
                const fullName = [
                    document.getElementById('first_name').value,
                    document.getElementById('middle_name').value,
                    document.getElementById('last_name').value,
                ].filter(Boolean).join(' ');
                const pricingSnapshot = (typeof getPricingSnapshot === 'function') ? getPricingSnapshot() : {};
                const baseRate = pricingSnapshot.baseRateText || '₱0.00';
                const distance = pricingSnapshot.distanceText || '0 km';
                const eta = pricingSnapshot.etaText || 'Pending route';
                const rate = pricingSnapshot.perKmRateText || '';
                const distanceFee = pricingSnapshot.distanceFeeText || '₱0.00';
                const excessKm = pricingSnapshot.excessKmText || '';
                const excessFee = pricingSnapshot.excessFeeText || '₱0.00';
                const discount = pricingSnapshot.discountAmountText || '₱0.00';
                const total = pricingSnapshot.totalText || '₱0.00';

                const customerItems = [{
                        label: 'Name',
                        value: fullName || 'Not provided',
                        wide: true
                    },
                    age ? {
                        label: 'Age',
                        value: age
                    } : null,
                    phone ? {
                        label: 'Phone',
                        value: phone
                    } : null,
                    email ? {
                        label: 'Email',
                        value: email
                    } : null,
                    {
                        label: 'Customer Type',
                        value: customerType
                    },
                ];

                const tripItems = [{
                        label: 'Booking Mode',
                        value: serviceType
                    },
                    {
                        label: 'Preferred Dispatch',
                        value: scheduleText
                    },
                    {
                        label: 'Vehicle Type',
                        value: vehicle
                    },
                    vehicleCategory ? {
                        label: 'Customer Vehicle',
                        value: vehicleCategory
                    } : null,
                    {
                        label: 'Pickup',
                        value: pickup || 'Not selected',
                        wide: true
                    },
                    {
                        label: 'Drop-off',
                        value: dropoff || 'Not selected',
                        wide: true
                    },
                    notes ? {
                        label: 'Special Notes',
                        value: notes,
                        wide: true
                    } : null,
                ];

                const fareItems = [{
                        label: 'Base Rate',
                        value: baseRate
                    },
                    {
                        label: 'Distance',
                        value: distance
                    },
                    rate ? {
                        label: 'Rate per KM',
                        value: rate
                    } : null,
                    {
                        label: 'Distance Fee',
                        value: distanceFee
                    },
                    {
                        label: 'Excess Fee',
                        value: excessFee
                    },
                    discount && discount !== '₱0.00' ? {
                        label: 'Discount',
                        value: discount
                    } : null,
                ];

                bookingSummary.innerHTML = `
                    <div class="summary-card">
                        ${renderSummarySection('Customer Information', customerItems)}
                        ${renderSummarySection('Trip Details', tripItems)}
                        ${renderSummarySection('Fare Summary', fareItems, {
                            totalValue: total,
                            helperNote: 'This estimate updates from your current route, vehicle, and booking mode.'
                        })}
                    </div>
                `;

                confirmationModal.classList.add('modal-open');
            }

            confirmBookingBtn.addEventListener('click', function() {
                isConfirmed = true;
                confirmBookingBtn.disabled = true;
                confirmBookingBtn.textContent = 'Processing...';

                if (typeof bookingForm.requestSubmit === 'function') {
                    bookingForm.requestSubmit();
                    return;
                }

                bookingForm.submit();
            });

            editBookingBtn.addEventListener('click', function() {
                resetConfirmationState();
            });

            window.addEventListener('click', function(event) {
                if (event.target === confirmationModal) {
                    resetConfirmationState();
                }
            });
        </script>
        <script src="{{ asset('customer/js/map.js') }}?v={{ filemtime(public_path('customer/js/map.js')) }}"></script>
    @endpush
@endsection
