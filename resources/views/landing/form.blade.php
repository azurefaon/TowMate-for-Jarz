@extends('landing.layouts.app')

@section('content')
    @push('styles')
        <link rel="stylesheet" href="{{ asset('home_page/css/landing.css') }}">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
        <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    @endpush

    <div class="landing-wrapper">

        <nav class="landing-nav">
            <h2 class="logo">JARZ</h2>

            <div class="nav-links" id="navMenu">
                <span class="nav-indicator"></span>
                <a href="{{ route('landing') }}">Home</a>
                <a href="{{ route('landing') }}#about">About</a>
                <a href="{{ route('landing') }}#services">Services</a>
                <a href="{{ route('landing') }}#contact">Contact</a>
            </div>

            <div class="nav-right-space"></div>

            <div class="menu-toggle" id="menuToggle">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </nav>

        <!-- BOOKING FORM -->
        <section class="section" id="booking">
            <div class="booking-container">
                <div class="booking-header">
                    <h2>Book Your Towing Service</h2>
                    <p class="booking-sub">
                        Choose Book Now for urgent towing or Schedule Later for a planned pickup. We'll route your request
                        to the dispatcher with the right timing.
                    </p>
                </div>

                <div class="booking-content">
                    <div class="booking-form-container">
                        <form class="booking-form" action="{{ route('landing.book.store') }}" method="POST"
                            enctype="multipart/form-data">
                            @csrf

                            <div class="form-section">
                                <h3>Customer Information</h3>

                                <div class="form-row">
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
                                        <small class="form-help">If you enter 9XXXXXXXXX, it will be corrected
                                            automatically.</small>
                                        @error('phone')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    <div class="form-group">
                                        <label for="email">Email Address</label>
                                        <input type="email" id="email" name="email" placeholder="yourname@gmail.com"
                                            value="{{ old('email') }}">
                                        <small class="form-help">Email must be valid and able to receive system
                                            notifications and receipts.</small>
                                        @error('email')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <input type="hidden" name="confirmation_type" value="system">

                                <div class="form-section">
                                    <h3>Service Timing</h3>

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
                                            <small class="form-help">Choose Book Now for urgent towing or Schedule Later
                                                for a planned pickup.</small>
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

                                <div class="form-section">
                                    <h3>Service Details</h3>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="truck_type_id">Vehicle Type *</label>
                                            <select id="truck_type_id" name="truck_type_id" required>
                                                <option value="">Select vehicle type</option>
                                                @foreach ($truckTypes as $type)
                                                    <option value="{{ $type->id }}"
                                                        {{ (string) old('truck_type_id') === (string) $type->id ? 'selected' : '' }}>
                                                        {{ $type->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <small class="form-help">Rates are loaded automatically from the active truck
                                                type.</small>
                                            @error('truck_type_id')
                                                <span class="error-message">{{ $message }}</span>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="pickup_address">Pickup Location *</label>
                                        <input type="text" id="pickup_address" name="pickup_address"
                                            placeholder="Enter pickup address" required
                                            value="{{ old('pickup_address') }}">
                                        <input type="hidden" id="pickup_lat" name="pickup_lat">
                                        <input type="hidden" id="pickup_lng" name="pickup_lng">
                                        @error('pickup_address')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>

                                    <div class="form-group">
                                        <label for="dropoff_address">Drop-off Location *</label>
                                        <input type="text" id="dropoff_address" name="dropoff_address"
                                            placeholder="Enter drop-off address" required
                                            value="{{ old('dropoff_address') }}">
                                        <input type="hidden" id="drop_lat" name="drop_lat">
                                        <input type="hidden" id="drop_lng" name="drop_lng">
                                        @error('dropoff_address')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h3>Additional Information</h3>

                                    <div class="form-group">
                                        <label for="vehicle_image">Vehicle Image</label>
                                        <input type="file" id="vehicle_image" name="vehicle_image"
                                            accept=".jpg,.jpeg,.png">
                                        <small class="form-help">Accepted formats: JPG, JPEG, PNG only.</small>
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
                                    <button type="submit" class="btn-primary" id="submitBookingBtn">
                                        <span>Book Now</span>
                                        <i class='bx bx-check-circle'></i>
                                    </button>
                                </div>
                        </form>

                        <div class="modal-overlay" id="confirmationModal">
                            <div class="modal-dialog">
                                <h3>Confirm Your Booking</h3>
                                <div class="modal-body" id="bookingSummary"></div>
                                <div class="modal-actions">
                                    <button type="button" class="btn-secondary" id="editBookingBtn">Edit</button>
                                    <button type="button" class="btn-primary" id="confirmBookingBtn">Confirm
                                        Booking</button>
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
                    clearFieldError(field);
                });
            });

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

            bookingForm.addEventListener('submit', function(event) {
                if (!isConfirmed) {
                    event.preventDefault();

                    if (!validateBookingForm()) {
                        return;
                    }

                    showConfirmationSummary();
                }
            });

            function validateBookingForm() {
                const requiredFields = ['first_name', 'last_name', 'age', 'phone', 'truck_type_id', 'pickup_address',
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
                }

                if (scheduledTimeInput) {
                    scheduledTimeInput.required = isScheduled;
                }

                if (submitBookingBtn) {
                    submitBookingBtn.querySelector('span').textContent = isScheduled ? 'Schedule Booking' : 'Book Now';
                }
            }

            toggleScheduleFields();
            serviceTypeInput?.addEventListener('change', toggleScheduleFields);

            function showConfirmationSummary() {
                const pickup = document.getElementById('pickup_address').value;
                const dropoff = document.getElementById('dropoff_address').value;
                const vehicleSelect = document.getElementById('truck_type_id');
                const vehicle = vehicleSelect.options[vehicleSelect.selectedIndex]?.text || '';
                const phone = document.getElementById('phone').value;
                const email = document.getElementById('email').value;
                const serviceType = serviceTypeInput?.value === 'schedule' ? 'Scheduled Later' : 'Book Now';
                const scheduleText = serviceTypeInput?.value === 'schedule' ?
                    `${scheduledDateInput?.value || 'N/A'} ${scheduledTimeInput?.value || ''}`.trim() : 'Immediate dispatch';
                const fullName = [
                    document.getElementById('first_name').value,
                    document.getElementById('middle_name').value,
                    document.getElementById('last_name').value,
                ].filter(Boolean).join(' ');
                const summaryHtml = `
                    <p><strong>Name:</strong> ${fullName}</p>
                    <p><strong>Age:</strong> ${document.getElementById('age').value}</p>
                    <p><strong>Phone:</strong> ${phone}</p>
                    <p><strong>Email:</strong> ${email || 'N/A'}</p>
                    <p><strong>Customer Type:</strong> ${document.getElementById('customer_type').value.toUpperCase()}</p>
                    <p><strong>Booking Mode:</strong> ${serviceType}</p>
                    <p><strong>Preferred Dispatch:</strong> ${scheduleText}</p>
                    <p><strong>Vehicle Type:</strong> ${vehicle}</p>
                    <p><strong>Pickup:</strong> ${pickup}</p>
                    <p><strong>Drop-off:</strong> ${dropoff}</p>
                `;

                bookingSummary.innerHTML = summaryHtml;
                confirmationModal.classList.add('modal-open');
            }

            confirmBookingBtn.addEventListener('click', function() {
                isConfirmed = true;

                if (typeof bookingForm.requestSubmit === 'function') {
                    bookingForm.requestSubmit();
                    return;
                }

                bookingForm.submit();
            });

            editBookingBtn.addEventListener('click', function() {
                confirmationModal.classList.remove('modal-open');
            });

            window.addEventListener('click', function(event) {
                if (event.target === confirmationModal) {
                    confirmationModal.classList.remove('modal-open');
                }
            });
        </script>
    @endpush>
@endsection
