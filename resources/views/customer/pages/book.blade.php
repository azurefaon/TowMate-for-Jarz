@extends('customer.layouts.app')

@section('title', 'Book a Tow')

@section('content')
    <link rel="stylesheet" href="{{ asset('customer/css/book.css') }}">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />

    <div class="book-wrapper">

        <div class="book-header">
            <span class="book-kicker">Simple and guided booking</span>
            <h2>Book a Tow</h2>
            <p>Fill in the essentials, set the map pins, choose the vehicle, and confirm your booking fast.</p>
        </div>

        <div class="book-grid">

            <div class="left-side">
                <div class="map-card route-preview-card">
                    <div class="route-preview-head">
                        <div>
                            <h3>Route Preview</h3>
                            <p>Your confirmed pickup and dropoff will appear here.</p>
                        </div>
                        <div class="route-preview-legend">
                            <span><i class='bx bxs-circle pickup-text'></i> Pickup</span>
                            <span><i class='bx bxs-circle dropoff-text'></i> Dropoff</span>
                        </div>
                    </div>
                    <div id="map"></div>
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
                    <input type="hidden" name="pickup_confirmed" id="pickupConfirmedInput"
                        value="{{ old('pickup_confirmed', 0) }}">
                    <input type="hidden" name="dropoff_confirmed" id="dropoffConfirmedInput"
                        value="{{ old('dropoff_confirmed', 0) }}">
                    <input type="hidden" name="distance" id="distance_input">
                    <input type="hidden" name="price" id="price_input">
                    <input type="hidden" name="additional_fee" id="additional_fee_input" value="0">

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
                                    <label>Customer Vehicle Category</label>
                                    <select name="vehicle_category" id="vehicleCategory" required>
                                        <option value="">Select vehicle category</option>
                                        <option value="2_wheeler"
                                            {{ old('vehicle_category') === '2_wheeler' ? 'selected' : '' }}>2 Wheels
                                        </option>
                                        <option value="4_wheeler"
                                            {{ old('vehicle_category') === '4_wheeler' ? 'selected' : '' }}>4 Wheels
                                        </option>
                                        <option value="heavy_vehicle"
                                            {{ old('vehicle_category') === 'heavy_vehicle' || old('vehicle_category') === '6_wheeler' || old('vehicle_category') === '10_wheeler' ? 'selected' : '' }}>
                                            Heavy Vehicle (6+ Wheels)</option>
                                        <option value="other" {{ old('vehicle_category') === 'other' ? 'selected' : '' }}>
                                            Other</option>
                                    </select>
                                </div>
                                <div class="input-group">
                                    <label>Vehicle Image</label>
                                    <input type="file" name="vehicle_image" accept=".jpg,.jpeg,.png">
                                </div>
                            </div>

                            <div class="row">
                                <div class="input-group">
                                    <label>Landmark / Pickup Note</label>
                                    <textarea name="pickup_notes" id="pickupNotes" rows="3"
                                        placeholder="Optional landmark, gate number, or roadside note...">{{ old('pickup_notes') }}</textarea>
                                </div>
                                <div class="input-group">
                                    <label>Discount Code</label>
                                    <input type="text" name="discount_code" id="discountCode"
                                        value="{{ old('discount_code') }}" placeholder="Optional validated code">
                                </div>
                            </div>

                            <div class="input-group">
                                <label>Special Notes / Additional Directions</label>
                                <textarea name="notes" rows="3" placeholder="Any special instructions or notes...">{{ old('notes') }}</textarea>
                            </div>

                            <div class="location-quick-card">
                                <div class="location-quick-head">
                                    <h4>Pickup and Dropoff</h4>
                                    <p>Type the address or tap the map icon to pin the exact spot.</p>
                                </div>

                                <div class="location-group">
                                    <div class="location-item">
                                        <div class="input-wrapper">
                                            <label>Pickup Location</label>
                                            <div class="input-map-wrapper with-action">
                                                <input type="text" id="pickup" name="pickup_address"
                                                    value="{{ old('pickup_address') }}"
                                                    placeholder="Where should we pick you up?" required>
                                                <button type="button" class="map-open-btn" id="openPickupPicker"
                                                    aria-label="Pick pickup on map">
                                                    <i class='bx bx-map-pin'></i>
                                                </button>
                                                <div id="pickupSuggestions" class="suggestions"></div>
                                            </div>
                                            <div class="inline-location-status" id="pickupStatusWrap"
                                                style="display:none;">
                                                <span class="pickup-status-badge pending"
                                                    id="pickupStatusBadge">Pinned</span>
                                                <p id="pickupStatusText"></p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="location-item">
                                        <div class="input-wrapper">
                                            <label>Dropoff Location</label>
                                            <div class="input-map-wrapper with-action">
                                                <input type="text" id="dropoff" name="dropoff_address"
                                                    value="{{ old('dropoff_address') }}"
                                                    placeholder="Where are you headed?" required>
                                                <button type="button" class="map-open-btn" id="openDropoffPicker"
                                                    aria-label="Pick dropoff on map">
                                                    <i class='bx bx-map'></i>
                                                </button>
                                                <div id="dropSuggestions" class="suggestions"></div>
                                            </div>
                                            <div class="inline-location-status" id="dropoffStatusWrap"
                                                style="display:none;">
                                                <span class="pickup-status-badge pending"
                                                    id="dropoffStatusBadge">Pinned</span>
                                                <p id="dropoffStatusText"></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="pickup-status-card">
                                    <div class="pickup-status-head">
                                        <div>
                                            <h3>Map Confirmation Flow</h3>
                                            <p>Search the address, drag the marker if needed, and confirm both pins before
                                                submitting.</p>
                                        </div>
                                        <span class="pickup-status-badge pending">Required</span>
                                    </div>

                                    <div class="location-confirm-item">
                                        <div>
                                            <strong>Pickup Pin</strong>
                                            <p>Confirm the roadside or landmark point for pickup.</p>
                                        </div>
                                        <button type="button" class="pickup-primary-btn" id="confirmPickupBtn">Set
                                            Pickup Pin</button>
                                    </div>

                                    <div class="location-confirm-item dropoff-row">
                                        <div>
                                            <strong>Dropoff Pin</strong>
                                            <p>Confirm the final destination point for the tow request.</p>
                                        </div>
                                        <button type="button" class="pickup-primary-btn dropoff-btn"
                                            id="confirmDropoffBtn">Set Dropoff Pin</button>
                                    </div>
                                </div>
                            </div>

                            <div class="divider"></div>


                            <div class="row">

                                <div class="input-group">
                                    <label>Vehicle Type</label>
                                    <select name="truck_type_id" id="vehicleType" required>
                                        <option value="">Select vehicle</option>
                                        @foreach ($truckTypes as $type)
                                            @php $isUnavailable = ($type->status ?? 'active') !== 'active'; @endphp
                                            <option value="{{ $type->id }}" data-base="{{ $type->base_rate }}"
                                                data-perkm="{{ $type->per_km_rate }}" @selected((string) old('truck_type_id') === (string) $type->id)
                                                @disabled($isUnavailable)>
                                                {{ $type->name }}{{ $isUnavailable ? ' (Unavailable)' : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <small style="color:#6b7280; display:block; margin-top:6px;">Gray vehicle types are
                                        currently unavailable and cannot be selected.</small>
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
                                Live Price Preview
                            </div>

                            <div class="cost-row">
                                <span>Base Price:</span>
                                <strong id="baseRate">₱0.00</strong>
                            </div>

                            <div class="cost-row">
                                <span>Distance:</span>
                                <strong id="distance">0 km</strong>
                            </div>

                            <div class="cost-row">
                                <span>Estimated ETA:</span>
                                <strong id="eta">Pending route</strong>
                            </div>

                            <div class="cost-row">
                                <span>Rate per KM:</span>
                                <strong id="rate">₱0</strong>
                            </div>

                            <div class="cost-row">
                                <span>Distance Fee:</span>
                                <strong id="distanceFee">₱0.00</strong>
                            </div>

                            <div class="cost-row">
                                <span>Excess Fee:</span>
                                <strong id="excessFee">₱0.00</strong>
                            </div>

                            <div class="cost-row">
                                <span>Additional Fees:</span>
                                <strong id="additionalFee">₱0.00</strong>
                            </div>

                            <div class="cost-row">
                                <span>Discount:</span>
                                <strong id="discountAmount">₱0.00</strong>
                            </div>

                            <div class="cost-note" id="discountMeta">
                                Optional discounts are validated automatically before submission.
                            </div>

                            <div class="cost-row">
                                <span>Dispatch Status:</span>
                                <strong id="availabilityStatus">Checking...</strong>
                            </div>

                            <div class="cost-total">
                                <span>Estimated Total:</span>
                                <h2 id="price">₱0.00</h2>
                            </div>

                            <div class="cost-note" id="availabilityNote">
                                Select your pickup pin, dropoff pin, and vehicle to see the live estimate.
                            </div>

                            <button type="button" id="bookBtn">
                                Book Now
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
                    <span class="summary-kicker">Booking Review</span>
                    <h3 id="summaryModalTitle">Book Now</h3>
                    <p id="summaryModalSubtitle">Please review your details and estimated fare before sending the request.
                    </p>
                </div>

                <div class="modal-body">
                    <div class="summary-card" id="bookingSummaryContent">
                        <div class="summary-loading">Preparing your booking summary...</div>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" id="cancelBtn">Back</button>
                    <button type="button" id="confirmBtn">Confirm Book Now</button>
                </div>

            </div>

        </div>

        <div id="pickupConfirmModal" class="confirm-modal hidden">
            <div class="booking-modal pickup-preview-modal location-picker-modal">
                <div class="modal-header">
                    <span class="summary-kicker">Location Picker</span>
                    <h3 id="locationConfirmTitle">Select Pickup Location</h3>
                    <p id="locationConfirmSubtitle">Center the map on the exact roadside spot and confirm the pin.</p>
                </div>

                <div class="modal-body">
                    <div class="picker-toolbar">
                        <button type="button" class="current-location-btn" id="useCurrentLocationBtn">
                            <i class='bx bx-current-location'></i>
                            Use Current Location
                        </button>
                    </div>

                    <div class="pickup-preview-map-wrap">
                        <div id="pickupPreviewMap" class="pickup-preview-map"></div>
                        <div class="center-pin modal-center-pin"></div>
                    </div>

                    <div class="location-confirm-item">
                        <div>
                            <strong id="pickupPreviewAddress">Loading location...</strong>
                            <p id="pickupPreviewNotes">Move the map until the pin matches the correct point.</p>
                        </div>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" id="pickupAdjustModalBtn">Edit Position</button>
                    <button type="button" id="pickupConfirmModalBtn">Confirm Location</button>
                </div>
            </div>
        </div>

    </div>

    <script>
        window.bookingGeoConfig = {
            searchUrl: @json(route('geo.search')),
            reverseUrl: @json(route('geo.reverse')),
            routeUrl: @json(route('geo.route')),
            pricingPreviewUrl: @json(route('geo.pricing.preview')),
            csrfToken: @json(csrf_token()),
        };
    </script>
    {{-- Google Maps is disabled for now while Leaflet is active.
    <script
        src="https://maps.googleapis.com/maps/api/js?key={{ urlencode(config('services.google_maps.key')) }}&libraries=places"
        defer></script>
    --}}
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script src="{{ asset('customer/js/map.js') }}?v={{ filemtime(public_path('customer/js/map.js')) }}"></script>
    <script src="{{ asset('customer/js/dashboard.js') }}"></script>
    <script src="{{ asset('customer/js/history.js') }}"></script>
@endsection
