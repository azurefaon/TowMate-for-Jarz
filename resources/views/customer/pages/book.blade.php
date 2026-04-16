@extends('customer.layouts.app')

@section('title', 'Book a Tow')

@section('content')
    <link rel="stylesheet" href="{{ asset('customer/css/book.css') }}">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />

    <div class="book-wrapper">

        <div class="book-header">
            <h2>Book a Tow</h2>
            <p>Choose a fast Book Now request for urgent roadside help, or schedule a pickup for later.</p>
        </div>

        <div class="book-grid">

            <div class="left-side">
                <div class="map-card">
                    <div id="map"></div>
                </div>

                <div class="guide-card">
                    <h3>How to Book</h3>

                    <div class="guide-steps">
                        <div class="guide-step">
                            <div class="step-icon">📍</div>
                            <div>
                                <strong>Select Pickup</strong>
                                <p>Click map or type location</p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-icon">🏁</div>
                            <div>
                                <strong>Select Dropoff</strong>
                                <p>Click map again or type</p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-icon">📏</div>
                            <div>
                                <strong>Auto Calculation</strong>
                                <p>System computes distance & price</p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-icon">🚚</div>
                            <div>
                                <strong>Confirm Booking</strong>
                                <p>Wait for driver assignment</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="right-side">
                <form id="bookingForm" method="POST" action="{{ route('customer.book.store') }}"
                    enctype="multipart/form-data">
                    @csrf

                    <input type="hidden" name="pickup_lat" id="pickup_lat">
                    <input type="hidden" name="pickup_lng" id="pickup_lng">
                    <input type="hidden" name="drop_lat" id="drop_lat">
                    <input type="hidden" name="drop_lng" id="drop_lng">
                    <input type="hidden" name="distance" id="distance_input">
                    <input type="hidden" name="price" id="price_input">

                    <div class="form-card">
                        <div class="form-inner">

                            <input type="hidden" name="confirmation_type" value="system">

                            <div class="row">
                                <div class="input-group">
                                    <label>First Name</label>
                                    <input type="text" name="first_name"
                                        value="{{ old('first_name', optional(Auth::user())->first_name) }}" required>
                                </div>
                                <div class="input-group">
                                    <label>Middle Name</label>
                                    <input type="text" name="middle_name"
                                        value="{{ old('middle_name', optional(Auth::user())->middle_name) }}">
                                </div>
                                <div class="input-group">
                                    <label>Last Name</label>
                                    <input type="text" name="last_name"
                                        value="{{ old('last_name', optional(Auth::user())->last_name) }}" required>
                                </div>
                            </div>

                            <div class="row">
                                <div class="input-group">
                                    <label>Age</label>
                                    <input type="number" name="age" min="1" max="120"
                                        value="{{ old('age', optional(optional(Auth::user())->customer)->age) }}" required>
                                </div>
                                <div class="input-group">
                                    <label>Phone Number</label>
                                    <input type="tel" id="customer_phone" name="phone"
                                        value="{{ old('phone', optional(optional(Auth::user())->customer)->phone) }}"
                                        placeholder="09123456789" required>
                                </div>
                                <div class="input-group">
                                    <label>Email</label>
                                    <input type="email" name="email"
                                        value="{{ old('email', optional(Auth::user())->email) }}"
                                        placeholder="yourname@gmail.com">
                                </div>
                            </div>

                            <div class="row">
                                <div class="input-group">
                                    <label>Customer Type</label>
                                    <select name="customer_type" required>
                                        <option value="regular"
                                            {{ old('customer_type', optional(optional(Auth::user())->customer)->customer_type ?? 'regular') === 'regular' ? 'selected' : '' }}>
                                            Regular</option>
                                        <option value="pwd"
                                            {{ old('customer_type', optional(optional(Auth::user())->customer)->customer_type) === 'pwd' ? 'selected' : '' }}>
                                            PWD</option>
                                        <option value="senior"
                                            {{ old('customer_type', optional(optional(Auth::user())->customer)->customer_type) === 'senior' ? 'selected' : '' }}>
                                            Senior</option>
                                    </select>
                                </div>
                                <div class="input-group">
                                    <label>Vehicle Image</label>
                                    <input type="file" name="vehicle_image" accept=".jpg,.jpeg,.png">
                                </div>
                            </div>

                            <div class="input-group">
                                <label>Special Notes</label>
                                <textarea name="notes" rows="3" placeholder="Any special instructions or notes...">{{ old('notes') }}</textarea>
                            </div>

                            <div class="location-group">

                                <div class="location-item">
                                    <div class="input-wrapper">
                                        <label>Pickup Location</label>
                                        <div class="input-map-wrapper">
                                            <input type="text" id="pickup" name="pickup_address" required>
                                            <div id="pickupSuggestions" class="suggestions"></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="location-item">
                                    <div class="input-wrapper">
                                        <label>Dropoff Location</label>
                                        <div class="input-map-wrapper">
                                            <input type="text" id="dropoff" name="dropoff_address" required>
                                            <div id="dropSuggestions" class="suggestions"></div>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <div class="divider"></div>

                            <div class="row">

                                <div class="input-group">
                                    <label>Vehicle Type</label>
                                    <select name="truck_type_id" id="vehicleType">
                                        <option value="">Select vehicle</option>
                                        @foreach ($truckTypes as $type)
                                            <option value="{{ $type->id }}" data-base="{{ $type->base_rate }}"
                                                data-perkm="{{ $type->per_km_rate }}">
                                                {{ $type->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="input-group">
                                    <label>Booking Mode</label>
                                    <select name="service_type" id="serviceType" required>
                                        <option value="book_now"
                                            {{ old('service_type', 'book_now') === 'book_now' ? 'selected' : '' }}>Book Now
                                        </option>
                                        <option value="schedule"
                                            {{ old('service_type') === 'schedule' ? 'selected' : '' }}>Schedule Later
                                        </option>
                                    </select>
                                    <small class="cost-note" style="margin-top:8px; display:block;">Book Now is best for
                                        urgent towing. Schedule Later is for planned dispatch.</small>
                                </div>

                            </div>

                            <div class="row" id="scheduleFields"
                                style="display: {{ old('service_type') === 'schedule' ? 'grid' : 'none' }};">
                                <div class="input-group">
                                    <label>Preferred Date</label>
                                    <input type="date" name="scheduled_date" id="scheduledDate"
                                        min="{{ now()->toDateString() }}" value="{{ old('scheduled_date') }}">
                                </div>
                                <div class="input-group">
                                    <label>Preferred Time</label>
                                    <input type="time" name="scheduled_time" id="scheduledTime"
                                        value="{{ old('scheduled_time') }}">
                                </div>
                            </div>

                        </div>

                        <div class="cost-card">

                            <div class="cost-title">
                                Cost Estimate
                            </div>

                            <div class="cost-row">
                                <span>Distance:</span>
                                <strong id="distance">0 km</strong>
                            </div>

                            <div class="cost-row">
                                <span>Rate per KM:</span>
                                <strong id="rate">₱0</strong>
                            </div>

                            <div class="cost-total">
                                <span>Estimated Total:</span>
                                <h2 id="price">₱0.00</h2>
                            </div>

                            <div class="cost-note">
                                This is an estimated cost. Final price may vary based on actual conditions.
                            </div>

                            <button type="button" id="bookBtn">
                                Request Towing Service
                            </button>

                        </div>

                    </div>
                </form>
            </div>

        </div>

        <div id="confirmModal" class="confirm-modal hidden">

            <div class="booking-modal">

                <!-- HEADER -->
                <div class="modal-header">
                    <h3>Confirm Booking</h3>
                    <p>Please review your booking details</p>
                </div>

                <!-- SUMMARY -->
                <div class="modal-body">

                    <div class="summary-card">

                        <div class="summary-row">
                            <span>Pickup</span>
                            <strong id="summaryPickup"></strong>
                        </div>

                        <div class="summary-row">
                            <span>Dropoff</span>
                            <strong id="summaryDropoff"></strong>
                        </div>

                        <div class="summary-row">
                            <span>Vehicle</span>
                            <strong id="summaryVehicle"></strong>
                        </div>

                        <div class="summary-row">
                            <span>Booking Mode</span>
                            <strong id="summaryService"></strong>
                        </div>

                        <div class="summary-row">
                            <span>Preferred Dispatch</span>
                            <strong id="summarySchedule"></strong>
                        </div>

                        <div class="summary-row">
                            <span>Distance</span>
                            <strong id="summaryDistance"></strong>
                        </div>

                        <div class="summary-total">
                            <span>Total</span>
                            <h2 id="summaryPrice"></h2>
                        </div>

                    </div>

                </div>

                <div class="modal-actions">
                    <button id="cancelBtn">Cancel</button>
                    <button id="confirmBtn">Confirm Booking</button>
                </div>

            </div>

        </div>

    </div>

    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script src="{{ asset('customer/js/map.js') }}"></script>
    <script src="{{ asset('customer/js/dashboard.js') }}"></script>
    <script src="{{ asset('customer/js/history.js') }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const bookingForm = document.getElementById('bookingForm');
            const phoneInput = document.getElementById('customer_phone');
            const imageInput = document.querySelector('input[name="vehicle_image"]');
            const serviceTypeInput = document.getElementById('serviceType');
            const scheduleFields = document.getElementById('scheduleFields');
            const scheduledDateInput = document.getElementById('scheduledDate');
            const scheduledTimeInput = document.getElementById('scheduledTime');
            const bookBtn = document.getElementById('bookBtn');

            function ensureFieldErrorElement(input) {
                const container = input.closest('.input-group') || input.closest('.input-wrapper');

                if (!container) {
                    return null;
                }

                let errorElement = container.querySelector('.client-error-message');

                if (!errorElement) {
                    errorElement = document.createElement('small');
                    errorElement.className = 'client-error-message';
                    errorElement.style.display = 'block';
                    errorElement.style.marginTop = '6px';
                    errorElement.style.color = '#dc2626';
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

                const errorElement = ensureFieldErrorElement(input);
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

                const container = input.closest('.input-group') || input.closest('.input-wrapper');
                const errorElement = container ? container.querySelector('.client-error-message') : null;

                if (errorElement) {
                    errorElement.textContent = '';
                }
            }

            window.showBookingFieldError = setFieldError;
            window.clearBookingFieldError = clearFieldError;

            bookingForm?.querySelectorAll('input, select, textarea').forEach(function(field) {
                const eventName = field.type === 'file' || field.tagName === 'SELECT' ? 'change' : 'input';

                field.addEventListener(eventName, function() {
                    clearFieldError(field);
                });
            });

            phoneInput?.addEventListener('blur', function() {
                const value = this.value.trim();
                if (/^9\d{9}$/.test(value)) {
                    this.value = '0' + value;
                }

                if (this.value && !/^(09\d{9}|\+639\d{9})$/.test(this.value)) {
                    setFieldError(this, 'Please enter a valid Philippine phone number.');
                    this.reportValidity();
                    return;
                }

                clearFieldError(this);
            });

            imageInput?.addEventListener('change', function() {
                clearFieldError(this);

                const file = this.files?.[0];
                const allowedTypes = ['image/jpeg', 'image/png'];

                if (file && !allowedTypes.includes(file.type)) {
                    this.value = '';
                    setFieldError(this, 'Vehicle image must be a JPG or PNG file only.');
                    this.reportValidity();
                }
            });

            function syncScheduleMode() {
                const isScheduled = serviceTypeInput?.value === 'schedule';

                if (scheduleFields) {
                    scheduleFields.style.display = isScheduled ? 'grid' : 'none';
                }

                if (scheduledDateInput) {
                    scheduledDateInput.required = isScheduled;
                }

                if (scheduledTimeInput) {
                    scheduledTimeInput.required = isScheduled;
                }

                if (bookBtn) {
                    bookBtn.textContent = isScheduled ? 'Review Scheduled Booking' : 'Request Towing Service Now';
                }
            }

            serviceTypeInput?.addEventListener('change', syncScheduleMode);
            syncScheduleMode();
        });
    </script>
@endsection
