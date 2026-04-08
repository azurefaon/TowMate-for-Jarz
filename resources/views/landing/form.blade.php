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
                        Fill out the form below to schedule your towing service.
                        We'll dispatch the nearest available unit to your location.
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
                                        <label for="full_name">Full Name *</label>
                                        <input type="text" id="full_name" name="full_name" required
                                            value="{{ old('full_name') }}">
                                        @error('full_name')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    <div class="form-group">
                                        <label for="age">Age *</label>
                                        <input type="number" id="age" name="age" min="1" max="120"
                                            required value="{{ old('age') }}">
                                        @error('age')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="phone">Phone Number *</label>
                                        <input type="tel" id="phone" name="phone"
                                            placeholder="09123456789 or +639123456789" required
                                            value="{{ old('phone') }}">
                                        <small class="form-help">Enter Philippine mobile number (e.g., 09123456789)</small>
                                        @error('phone')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    <div class="form-group">
                                        <label for="email">Email Address</label>
                                        <input type="email" id="email" name="email" placeholder="yourname@gmail.com"
                                            value="{{ old('email') }}">
                                        <small class="form-help">Only Gmail addresses are accepted</small>
                                        @error('email')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>


                                <div class="form-section">
                                    <h3>Service Details</h3>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <input type="text" id="truck_type_id" name="truck_type_id"
                                                placeholder="Your Vehicle Type *" required>
                                            <small class="form-help">If unsure, select based on your car size: Sedan
                                                (compact), SUV (larger), Truck (commercial)</small>
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
                                        <input type="file" id="vehicle_image" name="vehicle_image" accept="image/*">
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

                                    <div class="form-checkboxes">
                                        <label class="checkbox-label">
                                            <input type="checkbox" name="is_pwd" value="1"
                                                {{ old('is_pwd') ? 'checked' : '' }}>
                                            <span class="checkmark"></span>
                                            Person With Disability (PWD)
                                        </label>

                                        <label class="checkbox-label">
                                            <input type="checkbox" name="is_senior" value="1"
                                                {{ old('is_senior') ? 'checked' : '' }}>
                                            <span class="checkmark"></span>
                                            Senior Citizen (60+ years)
                                        </label>
                                    </div>
                                </div>

                                <div class="form-actions">
                                    <button type="button" class="btn-secondary" onclick="history.back()">
                                        <i class='bx bx-arrow-back'></i>
                                        Back
                                    </button>
                                    <button type="submit" class="btn-primary">
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

            bookingForm.addEventListener('submit', function(event) {
                event.preventDefault();
                if (!validateBookingForm()) {
                    return;
                }
                showConfirmationSummary();
            });

            function validateBookingForm() {
                const requiredFields = ['full_name', 'age', 'phone', 'truck_type_id', 'pickup_address', 'dropoff_address'];
                let valid = true;

                requiredFields.forEach(function(field) {
                    const input = document.getElementById(field);
                    if (!input || !input.value || input.value.trim() === '') {
                        input.classList.add('input-error');
                        valid = false;
                    } else {
                        input.classList.remove('input-error');
                    }
                });

                // Additional validation for phone number format
                const phoneInput = document.getElementById('phone');
                const phoneRegex = /^(09\d{9}|\+639\d{9}|639\d{9})$/;
                if (phoneInput.value && !phoneRegex.test(phoneInput.value)) {
                    phoneInput.classList.add('input-error');
                    valid = false;
                }

                // Additional validation for Gmail email
                const emailInput = document.getElementById('email');
                const emailRegex = /^[a-zA-Z0-9._%+-]+@gmail\.com$/;
                if (emailInput.value && !emailRegex.test(emailInput.value)) {
                    emailInput.classList.add('input-error');
                    valid = false;
                }

                if (!valid) {
                    alert('Please fill in all required fields with valid information before submitting.');
                }

                return valid;
            }

            function showConfirmationSummary() {
                const pickup = document.getElementById('pickup_address').value;
                const dropoff = document.getElementById('dropoff_address').value;
                const vehicle = document.getElementById('truck_type_id').value;
                const phone = document.getElementById('phone').value;
                const email = document.getElementById('email').value;
                const summaryHtml = `
                    <p><strong>Name:</strong> ${document.getElementById('full_name').value}</p>
                    <p><strong>Age:</strong> ${document.getElementById('age').value}</p>
                    <p><strong>Phone:</strong> ${phone}</p>
                    <p><strong>Email:</strong> ${email || 'N/A'}</p>
                    <p><strong>Vehicle Type:</strong> ${vehicle}</p>
                    <p><strong>Pickup:</strong> ${pickup}</p>
                    <p><strong>Drop-off:</strong> ${dropoff}</p>
                `;

                bookingSummary.innerHTML = summaryHtml;
                confirmationModal.classList.add('modal-open');
            }

            confirmBookingBtn.addEventListener('click', function() {
                confirmationModal.classList.remove('modal-open');
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
