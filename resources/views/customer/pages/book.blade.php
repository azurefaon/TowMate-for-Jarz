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

                    <div class="form-card">
                        <div class="form-inner">

                            <div class="location-group">

                                <div class="location-item">
                                    <div class="input-wrapper">
                                        <label>Pickup Location</label>
                                        <div class="input-map-wrapper">
                                            <input type="text" id="pickup" name="pickup_address">
                                            <div id="pickupSuggestions" class="suggestions"></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="location-item">
                                    <div class="input-wrapper">
                                        <label>Dropoff Location</label>
                                        <div class="input-map-wrapper">
                                            <input type="text" id="dropoff" name="dropoff_address">
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
                            <div class="cost-row">
                                <span>Distance</span>
                                <strong id="distance">0 km</strong>
                            </div>

                            <div class="cost-total">
                                <span>Total</span>
                                <h2 id="price">₱0</h2>
                            </div>

                            <button type="button" id="bookBtn" onclick="openConfirmModal()">
                                Book Now
                            </button>
                        </div>

                    </div>
                </form>
            </div>

        </div>
    </div>

    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script src="{{ asset('customer/js/map.js') }}"></script>
@endsection
