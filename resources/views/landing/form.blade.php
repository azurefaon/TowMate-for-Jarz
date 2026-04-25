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
                                <input type="hidden" id="truck_type_id" name="truck_type_id"
                                    value="{{ optional($truckTypes->first())->id }}"
                                    data-base="{{ optional($truckTypes->first())->base_rate }}"
                                    data-perkm="{{ optional($truckTypes->first())->per_km_rate }}">
                                <input type="hidden" id="vehicle_category" name="vehicle_category" value="4_wheeler">

                                <!-- Hidden ETA field for backend -->
                                <input type="hidden" id="eta_minutes" name="eta_minutes" value="">

                                <div class="form-section compact-section">
                                    <h3>Booking Mode</h3>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="service_type">Booking Mode *</label>
                                            <select id="service_type" name="service_type" required>
                                                <option value="" disabled
                                                    {{ old('service_type') ? '' : 'selected' }}>
                                                    -- Select booking mode --
                                                </option>
                                                <option value="book_now"
                                                    {{ old('service_type') === 'book_now' ? 'selected' : '' }}>
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
                                        <input type="hidden" name="distance_km" id="distance_input">
                                        <input type="hidden" name="price" id="price_input">
                                        <input type="hidden" name="additional_fee" id="additional_fee_input"
                                            value="0">
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
                                        <label for="customer_vehicle_type">Your Vehicle Type *</label>
                                        <input type="text" name="customer_vehicle_type" id="customerVehicleType"
                                            placeholder="e.g., Sedan, SUV, Motorcycle, Truck"
                                            value="{{ old('customer_vehicle_type') }}" required>
                                        @error('customer_vehicle_type')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>


                            </div>

                            <div class="form-section compact-section">
                                <h3>Vehicle Details</h3>

                                <div class="form-group">
                                    <label style="color:#000;font-weight:600;">
                                        Vehicle Images <span style="color:#dc2626;font-weight:700;">*</span>
                                        <span
                                            style="font-weight:400;font-size:0.8rem;color:#dc2626;margin-left:4px;">(Required)</span>
                                    </label>
                                    <small style="display:block;margin:2px 0 8px;color:#64748b;font-size:0.8rem;">note:
                                        include your plate number in the image</small>

                                    <div id="upload_dropzone"
                                        style="border:2px dashed #d1d5db;border-radius:10px;padding:36px 20px;text-align:center;background:#fff;cursor:pointer;transition:border-color 0.2s,background 0.2s;">
                                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
                                            stroke="#9ca3af" stroke-width="1.5" stroke-linecap="round"
                                            stroke-linejoin="round" style="margin:0 auto 10px;display:block;">
                                            <polyline points="16 16 12 12 8 16"></polyline>
                                            <line x1="12" y1="12" x2="12" y2="21"></line>
                                            <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"></path>
                                        </svg>
                                        <p style="margin:0;font-size:0.9rem;color:#64748b;">Drag and drop or <span
                                                style="color:#000;font-weight:600;text-decoration:underline;text-underline-offset:2px;">browse</span>
                                            to choose a file</p>
                                    </div>

                                    <div id="upload_counter_row"
                                        style="display:none;justify-content:space-between;align-items:center;margin-top:10px;">
                                        <span id="upload_count_text"
                                            style="font-size:0.85rem;color:#000;font-weight:600;">0 of 5 uploaded</span>
                                        <button type="button" id="upload_cancel_btn"
                                            style="font-size:0.85rem;color:#64748b;background:none;border:none;cursor:pointer;padding:0;font-weight:500;">Cancel</button>
                                    </div>

                                    <input type="file" id="vehicle_images" name="vehicle_images[]"
                                        accept=".jpg,.jpeg,.png" style="display:none;">
                                    <div id="vehicle_images_preview" style="margin-top:8px;"></div>
                                    @error('vehicle_images')
                                        <span class="error-message">{{ $message }}</span>
                                    @enderror
                                    @foreach ($errors->get('vehicle_images.*') as $msg)
                                        <span class="error-message">{{ $msg }}</span>
                                    @endforeach
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

            <!-- Vehicle Type Selection Modal - REMOVED -->
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
        <script src="{{ asset('customer/js/booking-debug.js') }}?v={{ time() }}"></script>
        <script>
            // Ensure ETA is set before submitting the form
            function setEtaHiddenField() {
                // Try to get ETA from global or snapshot (depends on map.js/booking-debug.js)
                let eta = null;
                if (typeof currentEtaMinutes !== 'undefined') {
                    eta = currentEtaMinutes;
                } else if (typeof getPricingSnapshot === 'function') {
                    const snap = getPricingSnapshot();
                    if (snap && snap.etaMinutes !== undefined) {
                        eta = snap.etaMinutes;
                    }
                }
                // Fallback: try to parse from summary if needed
                if (!eta) {
                    const etaInput = document.getElementById('eta_minutes');
                    if (etaInput && etaInput.value) {
                        eta = etaInput.value;
                    }
                }
                // Set the hidden input
                const etaInput = document.getElementById('eta_minutes');
                if (etaInput) {
                    etaInput.value = eta && !isNaN(eta) ? Math.round(eta) : '';
                }
            }
            // Attach to confirm button
            document.addEventListener('DOMContentLoaded', function() {
                const confirmBookingBtn = document.getElementById('confirmBookingBtn');
                if (confirmBookingBtn) {
                    confirmBookingBtn.addEventListener('click', setEtaHiddenField);
                }
            });
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

                    showConfirmationSummary();
                }
            });

            function validateBookingForm() {
                const requiredFields = ['first_name', 'last_name', 'phone', 'customer_vehicle_type',
                    'pickup_address', 'dropoff_address', 'service_type'
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

                const vehicleImagesInput = document.getElementById('vehicle_images');
                const files = window.getUploadBucket ? window.getUploadBucket().files : vehicleImagesInput?.files;
                const allowedTypes = ['image/jpeg', 'image/png'];
                const maxSizeBytes = 10 * 1024 * 1024;

                const imgErrorTarget = vehicleImagesInput;
                if (!files || files.length === 0) {
                    setFieldError(imgErrorTarget, 'At least one vehicle image is required.');
                    firstInvalidField = firstInvalidField || imgErrorTarget;
                    valid = false;
                } else if (files.length > 5) {
                    setFieldError(imgErrorTarget, 'You may upload a maximum of 5 vehicle images.');
                    firstInvalidField = firstInvalidField || imgErrorTarget;
                    valid = false;
                } else {
                    for (let i = 0; i < files.length; i++) {
                        const f = files[i];
                        if (!allowedTypes.includes(f.type)) {
                            setFieldError(imgErrorTarget,
                                `Image ${i + 1}: Only JPG and PNG files are accepted.`);
                            firstInvalidField = firstInvalidField || imgErrorTarget;
                            valid = false;
                            break;
                        } else if (f.size > maxSizeBytes) {
                            setFieldError(imgErrorTarget, `Image ${i + 1}: Each image must not exceed 10 MB.`);
                            firstInvalidField = firstInvalidField || imgErrorTarget;
                            valid = false;
                            break;
                        }
                    }
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

            submitBookingBtn?.addEventListener('click', function() {
                if (!validateBookingForm()) {
                    return;
                }
                showConfirmationSummary();
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
                const customerVehicleType = document.getElementById('customerVehicleType').value;

                const phone = document.getElementById('phone').value;
                const email = document.getElementById('email').value;
                const notes = document.getElementById('notes').value;
                const serviceType = serviceTypeInput?.value === 'schedule' ? 'Scheduled Later' : 'Book Now';
                const scheduleText = serviceTypeInput?.value === 'schedule' ?
                    `${scheduledDateInput?.value || 'N/A'} ${scheduledTimeInput?.value || ''}`.trim() : 'Immediate dispatch';

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
                    phone ? {
                        label: 'Phone',
                        value: phone
                    } : null,
                    email ? {
                        label: 'Email',
                        value: email
                    } : null,
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
                        label: 'Customer Vehicle',
                        value: customerVehicleType || 'Not specified'
                    },
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
                    excessFee && excessFee !== '₱0.00' ? {
                        label: 'Excess Fee',
                        value: excessFee
                    } : null,
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
                setEtaHiddenField();

                const fd = new FormData(bookingForm);

                // Inject bucket files — cross-browser safe (bypasses read-only input.files)
                fd.delete('vehicle_images[]');
                const bkt = window.getUploadBucket ? window.getUploadBucket() : null;
                if (bkt) {
                    Array.from(bkt.files).forEach(function(file) {
                        fd.append('vehicle_images[]', file, file.name);
                    });
                }

                fetch(bookingForm.action, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                    })
                    .then(function(response) {
                        return response.json();
                    })
                    .then(function(data) {
                        window.location.href = data.redirect;
                    })
                    .catch(function() {
                        confirmBookingBtn.disabled = false;
                        confirmBookingBtn.textContent = getConfirmationActionLabel();
                        isConfirmed = false;
                    });
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

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const input = document.getElementById('vehicle_images');
                const preview = document.getElementById('vehicle_images_preview');
                const dropzone = document.getElementById('upload_dropzone');
                const counterRow = document.getElementById('upload_counter_row');
                const countText = document.getElementById('upload_count_text');
                const cancelBtn = document.getElementById('upload_cancel_btn');
                if (!input || !preview || !dropzone) return;

                const MAX = 5;
                const ALLOWED = ['image/jpeg', 'image/png'];
                const MAX_MB = 10 * 1024 * 1024;
                let bucket = new DataTransfer();
                let isUpdating = false;
                const scannedFiles = new WeakSet();

                dropzone.addEventListener('click', function() {
                    input.click();
                });

                dropzone.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    dropzone.style.borderColor = '#facc15';
                    dropzone.style.background = '#fefce8';
                });

                dropzone.addEventListener('dragleave', function() {
                    dropzone.style.borderColor = '#d1d5db';
                    dropzone.style.background = '#fff';
                });

                dropzone.addEventListener('drop', function(e) {
                    e.preventDefault();
                    dropzone.style.borderColor = '#d1d5db';
                    dropzone.style.background = '#fff';
                    processFiles(Array.from(e.dataTransfer.files));
                });

                input.addEventListener('change', function() {
                    if (isUpdating) return;
                    isUpdating = true;
                    processFiles(Array.from(this.files));
                    input.value = '';
                    isUpdating = false;
                });

                function processFiles(incoming) {
                    let err = '';
                    for (const file of incoming) {
                        if (bucket.files.length >= MAX) {
                            err = 'Maximum of 5 photos allowed.';
                            break;
                        }
                        if (!ALLOWED.includes(file.type)) {
                            err = `"${file.name}": only JPG/PNG accepted.`;
                            continue;
                        }
                        if (file.size > MAX_MB) {
                            err = `"${file.name}": exceeds 2 MB limit.`;
                            continue;
                        }
                        bucket.items.add(file);
                    }
                    updateCounter();
                    renderPreview();
                    showError(err);
                }

                function updateCounter() {
                    const count = bucket.files.length;
                    counterRow.style.display = count > 0 ? 'flex' : 'none';
                    countText.textContent = `${count} of ${MAX} uploaded`;
                }

                function renderPreview() {
                    preview.innerHTML = '';
                    Array.from(bucket.files).forEach(function(file, idx) {
                        const ext = file.name.split('.').pop().toUpperCase();
                        const sizeKb = (file.size / 1024).toFixed(0);
                        const isNew = !scannedFiles.has(file);
                        if (isNew) scannedFiles.add(file);

                        const row = document.createElement('div');
                        row.style.cssText =
                            'position:relative;border-radius:8px;overflow:hidden;background:#f1f5f9;margin-bottom:8px;cursor:pointer;';
                        row.addEventListener('click', function() {
                            showImagePreview(file);
                        });

                        // Scan progress bar — animates left→right on first render
                        const bar = document.createElement('div');
                        bar.style.cssText =
                            'position:absolute;top:0;left:0;height:100%;width:' + (isNew ? '0' : '100') +
                            '%;background:#facc15;opacity:0.3;transition:width 1.4s ease-in-out;pointer-events:none;';
                        row.appendChild(bar);

                        const content = document.createElement('div');
                        content.style.cssText =
                            'position:relative;display:flex;align-items:center;gap:10px;padding:10px 12px;';

                        // Thumbnail — shows actual image, click opens full preview
                        const thumb = document.createElement('img');
                        thumb.style.cssText =
                            'width:42px;height:42px;object-fit:cover;border-radius:5px;flex-shrink:0;border:2px solid #facc15;';
                        const thumbUrl = URL.createObjectURL(file);
                        thumb.src = thumbUrl;
                        thumb.onload = function() {
                            URL.revokeObjectURL(thumbUrl);
                        };

                        const badge = document.createElement('span');
                        badge.style.cssText =
                            'flex-shrink:0;font-size:0.62rem;font-weight:700;background:#facc15;color:#000;padding:3px 6px;border-radius:3px;letter-spacing:0.04em;';
                        badge.textContent = ext;

                        const info = document.createElement('div');
                        info.style.cssText = 'flex:1;min-width:0;';

                        const nameEl = document.createElement('p');
                        nameEl.style.cssText =
                            'margin:0;font-size:0.85rem;font-weight:600;color:#000;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
                        nameEl.textContent = file.name;

                        const sizeEl = document.createElement('p');
                        sizeEl.style.cssText = 'margin:0;font-size:0.75rem;color:#64748b;';
                        sizeEl.textContent = `${sizeKb} Kb`;

                        info.appendChild(nameEl);
                        info.appendChild(sizeEl);

                        const removeBtn = document.createElement('button');
                        removeBtn.type = 'button';
                        removeBtn.textContent = '×';
                        removeBtn.style.cssText =
                            'flex-shrink:0;width:24px;height:24px;border-radius:50%;background:#fff;color:#64748b;border:1px solid #cbd5e1;cursor:pointer;font-size:15px;font-weight:700;line-height:1;padding:0;';
                        removeBtn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            removeAt(idx);
                        });

                        content.appendChild(thumb);
                        content.appendChild(badge);
                        content.appendChild(info);
                        content.appendChild(removeBtn);
                        row.appendChild(content);
                        preview.appendChild(row);

                        // Trigger scan animation on newly added files
                        if (isNew) {
                            requestAnimationFrame(function() {
                                requestAnimationFrame(function() {
                                    bar.style.width = '100%';
                                });
                            });
                        }
                    });
                }

                function showImagePreview(file) {
                    const url = URL.createObjectURL(file);

                    const overlay = document.createElement('div');
                    overlay.style.cssText =
                        'position:fixed;inset:0;background:rgba(0,0,0,0.85);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;';

                    const img = document.createElement('img');
                    img.src = url;
                    img.style.cssText =
                        'max-width:90vw;max-height:88vh;border-radius:10px;object-fit:contain;box-shadow:0 25px 60px rgba(0,0,0,0.5);';
                    img.onload = function() {
                        URL.revokeObjectURL(url);
                    };

                    const closeBtn = document.createElement('button');
                    closeBtn.type = 'button';
                    closeBtn.textContent = '×';
                    closeBtn.style.cssText =
                        'position:absolute;top:16px;right:20px;background:#facc15;color:#000;border:none;width:38px;height:38px;border-radius:50%;font-size:22px;font-weight:700;cursor:pointer;line-height:1;padding:0;';
                    closeBtn.addEventListener('click', function() {
                        document.body.removeChild(overlay);
                    });

                    overlay.appendChild(img);
                    overlay.appendChild(closeBtn);
                    overlay.addEventListener('click', function(e) {
                        if (e.target === overlay) document.body.removeChild(overlay);
                    });
                    document.body.appendChild(overlay);
                }

                function removeAt(idx) {
                    const files = Array.from(bucket.files);
                    bucket = new DataTransfer();
                    files.forEach(function(f, i) {
                        if (i !== idx) bucket.items.add(f);
                    });
                    updateCounter();
                    renderPreview();
                    showError('');
                }

                cancelBtn.addEventListener('click', function() {
                    bucket = new DataTransfer();
                    input.value = '';
                    updateCounter();
                    renderPreview();
                    showError('');
                });

                function showError(msg) {
                    const container = input.closest('.form-group');
                    let errEl = container && container.querySelector('.img-accum-error');
                    if (!errEl) {
                        errEl = document.createElement('span');
                        errEl.className = 'error-message img-accum-error';
                        container && container.insertBefore(errEl, preview);
                    }
                    errEl.textContent = msg;
                }

                // Expose bucket so form validation and submission can read it cross-browser
                window.getUploadBucket = function() {
                    return bucket;
                };
            });
        </script>

        <script src="{{ asset('customer/js/map.js') }}?v={{ filemtime(public_path('customer/js/map.js')) }}"></script>
    @endpush
@endsection
