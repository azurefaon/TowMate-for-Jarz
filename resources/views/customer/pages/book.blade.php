@extends('customer.layouts.app')

@section('title', 'Book a Tow')

@section('content')
    <link rel="stylesheet" href="{{ asset('customer/css/book.css') }}">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />

    <div class="book-wrapper">

        <div class="book-header">
            <h2>Book a Tow</h2>
            <p>Enter your details for an instant recovery estimate.</p>
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
                <form id="bookingForm" method="POST" action="{{ route('customer.book.store') }}">
                    @csrf

                    <input type="hidden" name="pickup_lat" id="pickup_lat">
                    <input type="hidden" name="pickup_lng" id="pickup_lng">
                    <input type="hidden" name="drop_lat" id="drop_lat">
                    <input type="hidden" name="drop_lng" id="drop_lng">
                    <input type="hidden" name="distance" id="distance_input">
                    <input type="hidden" name="price" id="price_input">

                    <div class="form-card">
                        <div class="form-inner">

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
                                    <label>Service Speed</label>
                                    <select name="service_type" id="serviceType">
                                        <option value="standard">Standard</option>
                                        <option value="express">Express</option>
                                        <option value="scheduled">Scheduled</option>
                                    </select>
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
                            <span>Service</span>
                            <strong id="summaryService"></strong>
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
@endsection
