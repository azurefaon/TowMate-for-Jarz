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
                                <input type="hidden" id="truck_type_id" name="truck_type_id" value="">
                                <input type="hidden" id="truck_class_hidden" name="truck_class" value="">
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



                            </div>

                            <div class="form-section compact-section">
                                <h3>Vehicle Details</h3>

                                {{-- Truck Class Picker --}}
                                @php
                                    $lf_selectedTruck = old('truck_type_id', '');
                                    $lf_classLabel = ['light' => 'Light', 'medium' => 'Medium', 'heavy' => 'Heavy'];
                                    $lf_classColor = [
                                        'light' => '#1d4ed8',
                                        'medium' => '#7c3aed',
                                        'heavy' => '#c2410c',
                                    ];
                                    $lf_classBg = ['light' => '#eff6ff', 'medium' => '#faf5ff', 'heavy' => '#fff7ed'];
                                @endphp

                                <div class="form-group" style="margin-bottom:18px;">
                                    <label style="display:block;margin-bottom:10px;font-weight:600;color:#111;">Truck Type
                                        <span style="color:#dc2626;">*</span></label>

                                    <div id="lf_class_grid"
                                        style="display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:8px;">
                                        @foreach ($truckTypes as $truck)
                                            @php
                                                $cls = $truck->class ?? 'other';
                                                // Class-level availability: if every truck type in this class
                                                // has 0 dispatch-ready units, the whole class is unavailable
                                                // (e.g. customer scenario: Heavy class greyed out when no
                                                // online team leader is on a heavy unit).
                                                $classAvailableUnits = (int) ($classData[$cls]['available_units'] ?? 0);
                                                $classAvail = $classAvailableUnits > 0;
                                                $isAvail = $truck->available_units_count > 0 && $classAvail;
                                                $clsLbl = $lf_classLabel[$cls] ?? ucfirst($cls);
                                                $clsColor = $lf_classColor[$cls] ?? '#52525b';
                                                $clsBg = $lf_classBg[$cls] ?? '#f4f4f5';
                                                $isSel = (string) $lf_selectedTruck === (string) $truck->id;
                                            @endphp
                                            <div class="lf-class-card {{ $isSel ? 'lf-selected' : '' }} {{ !$isAvail ? 'lf-unavailable' : '' }}"
                                                data-class="{{ $cls }}" data-truck-id="{{ $truck->id }}"
                                                data-base="{{ (float) $truck->base_rate }}"
                                                data-perkm="{{ (float) $truck->per_km_rate }}"
                                                data-available="{{ $isAvail ? 1 : 0 }}"
                                                data-class-available="{{ $classAvail ? 1 : 0 }}" role="button"
                                                tabindex="{{ $isAvail ? 0 : -1 }}"
                                                aria-disabled="{{ !$isAvail ? 'true' : 'false' }}"
                                                style="
                                                    border: 2px solid {{ $isSel ? '#111827' : '#e5e7eb' }};
                                                    border-radius: 10px;
                                                    padding: 10px 12px;
                                                    cursor: {{ $isAvail ? 'pointer' : 'not-allowed' }};
                                                    background: {{ $isSel ? '#111827' : '#fff' }};
                                                    color: {{ $isSel ? '#fff' : '#111827' }};
                                                    opacity: {{ !$isAvail ? '.45' : '1' }};
                                                    transition: border-color .15s, background .15s;
                                                    user-select: none;
                                                ">

                                                {{-- Class badge --}}
                                                <span
                                                    style="
                                                    display:inline-block;
                                                    font-size:9px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;
                                                    padding:2px 7px;border-radius:999px;margin-bottom:6px;
                                                    background:{{ $isSel ? 'rgba(255,255,255,.15)' : $clsBg }};
                                                    color:{{ $isSel ? '#d4d4d8' : $clsColor }};
                                                "
                                                    class="lf-cls-badge">{{ $clsLbl }}</span>

                                                <div
                                                    style="font-size:13px;font-weight:800;line-height:1.3;margin-bottom:6px;">
                                                    {{ $truck->name }}</div>

                                                <div style="display:flex;align-items:center;gap:4px;font-size:10px;font-weight:600;margin-bottom:6px;color:{{ $isSel ? '#d4d4d8' : '#6b7280' }};"
                                                    class="lf-avail-row">
                                                    <span
                                                        style="width:6px;height:6px;border-radius:50%;background:{{ $isAvail ? '#22c55e' : '#9ca3af' }};display:inline-block;flex-shrink:0;"></span>
                                                    @if ($isAvail)
                                                        {{ $truck->available_units_count }}
                                                        unit{{ $truck->available_units_count !== 1 ? 's' : '' }}
                                                    @else
                                                        No units
                                                    @endif
                                                </div>

                                                <div style="font-size:10px;font-weight:700;color:{{ $isSel ? '#a1a1aa' : '#52525b' }};"
                                                    class="lf-rate-row">
                                                    ₱{{ number_format($truck->base_rate, 0) }} +
                                                    ₱{{ number_format($truck->per_km_rate, 0) }}/km
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div id="lf_all_unavail"
                                        style="display:none;font-size:12px;color:#6b7280;margin-top:8px;">
                                        No units available right now — switch to <strong>Schedule Later</strong> to reserve.
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="customer_vehicle_type">Your Vehicle Type *</label>
                                        <input type="text" name="customer_vehicle_type" id="customerVehicleType"
                                            placeholder="Sedan, SUV, Motorcycle, Truck"
                                            value="{{ old('customer_vehicle_type') }}" required>
                                        @error('customer_vehicle_type')
                                            <span class="error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>
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

                            {{-- ── Optional 2nd vehicle (multi-tow) ────────────────────────── --}}
                            <div class="form-section compact-section" id="v2_wrapper">
                                <div
                                    style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                                    <div>
                                        <h3 style="margin:0;">Need to tow another vehicle?</h3>
                                        <p style="margin:4px 0 0;font-size:0.85rem;color:#6b7280;">
                                            Add a second vehicle on the same booking. ETA &amp; pricing update
                                            automatically.
                                        </p>
                                    </div>
                                    <button type="button" id="v2_toggle_btn" class="btn-secondary"
                                        style="padding:10px 16px;border-radius:999px;font-weight:600;">
                                        + Add another vehicle
                                    </button>
                                </div>

                                <input type="hidden" id="add_second_vehicle" name="add_second_vehicle"
                                    value="{{ old('add_second_vehicle', '0') }}">

                                <div id="v2_panel"
                                    style="display:{{ old('add_second_vehicle') === '1' ? 'block' : 'none' }};margin-top:18px;border:1px dashed #e5e7eb;border-radius:14px;padding:18px;background:#fafafa;">

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="v2_customer_vehicle_type">Second Vehicle Type *</label>
                                            <input type="text" id="v2_customer_vehicle_type"
                                                name="vehicle_2_customer_vehicle_type"
                                                placeholder="Sedan, SUV, Motorcycle"
                                                value="{{ old('vehicle_2_customer_vehicle_type') }}">
                                            @error('vehicle_2_customer_vehicle_type')
                                                <span class="error-message">{{ $message }}</span>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="form-group" style="margin-bottom:14px;">
                                        <label style="display:block;margin-bottom:10px;font-weight:600;color:#111;">
                                            Truck Type for Vehicle 2 <span style="color:#dc2626;">*</span>
                                        </label>
                                        <input type="hidden" id="v2_truck_type_id" name="vehicle_2_truck_type_id"
                                            value="{{ old('vehicle_2_truck_type_id') }}">
                                        <div id="v2_class_grid"
                                            style="display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:8px;">
                                            @foreach ($truckTypes as $truck)
                                                @php
                                                    $cls = $truck->class ?? 'other';
                                                    $classAvailableUnits =
                                                        (int) ($classData[$cls]['available_units'] ?? 0);
                                                    $classAvail = $classAvailableUnits > 0;
                                                    $isAvail = $truck->available_units_count > 0 && $classAvail;
                                                    $clsLbl = $lf_classLabel[$cls] ?? ucfirst($cls);
                                                    $clsColor = $lf_classColor[$cls] ?? '#52525b';
                                                    $clsBg = $lf_classBg[$cls] ?? '#f4f4f5';
                                                @endphp
                                                <div class="v2-class-card" data-class="{{ $cls }}"
                                                    data-truck-id="{{ $truck->id }}"
                                                    data-base="{{ (float) $truck->base_rate }}"
                                                    data-perkm="{{ (float) $truck->per_km_rate }}"
                                                    data-available="{{ $isAvail ? 1 : 0 }}"
                                                    data-class-available="{{ $classAvail ? 1 : 0 }}" role="button"
                                                    tabindex="0"
                                                    style="border:2px solid #e5e7eb;border-radius:10px;padding:10px 12px;cursor:pointer;background:#fff;color:#111827;user-select:none;transition:border-color .15s, background .15s;">
                                                    <span class="v2-cls-badge"
                                                        style="display:inline-block;font-size:9px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;padding:2px 7px;border-radius:999px;margin-bottom:6px;background:{{ $clsBg }};color:{{ $clsColor }};">
                                                        {{ $clsLbl }}
                                                    </span>
                                                    <div
                                                        style="font-size:13px;font-weight:800;line-height:1.3;margin-bottom:6px;">
                                                        {{ $truck->name }}
                                                    </div>
                                                    <div class="v2-avail-row"
                                                        style="display:flex;align-items:center;gap:4px;font-size:10px;font-weight:600;margin-bottom:6px;color:#6b7280;">
                                                        <span
                                                            style="width:6px;height:6px;border-radius:50%;background:{{ $isAvail ? '#22c55e' : '#9ca3af' }};display:inline-block;flex-shrink:0;"></span>
                                                        @if ($isAvail)
                                                            {{ $truck->available_units_count }}
                                                            unit{{ $truck->available_units_count !== 1 ? 's' : '' }}
                                                        @else
                                                            No units
                                                        @endif
                                                    </div>
                                                    <div class="v2-rate-row"
                                                        style="font-size:10px;font-weight:700;color:#52525b;">
                                                        ₱{{ number_format($truck->base_rate, 0) }} +
                                                        ₱{{ number_format($truck->per_km_rate, 0) }}/km
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="form-group" style="margin-bottom:14px;">
                                        <label
                                            style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;color:#111;">
                                            <input type="checkbox" id="v2_route_override_chk"
                                                {{ old('vehicle_2_route_override') === '1' ? 'checked' : '' }}>
                                            This vehicle has a different pickup &amp; drop-off
                                        </label>
                                        <input type="hidden" id="v2_route_override" name="vehicle_2_route_override"
                                            value="{{ old('vehicle_2_route_override', '0') }}">
                                        <p style="margin:6px 0 0;font-size:0.8rem;color:#6b7280;">
                                            Leave unchecked to reuse the main pickup &amp; drop-off above.
                                        </p>
                                    </div>

                                    <div id="v2_route_fields"
                                        style="display:{{ old('vehicle_2_route_override') === '1' ? 'block' : 'none' }};">
                                        <div class="form-row form-row-location">
                                            <div class="form-group">
                                                <label for="v2_pickup_address">Vehicle 2 Pickup *</label>
                                                <div class="input-map-wrapper">
                                                    <input type="text" id="v2_pickup_address"
                                                        name="vehicle_2_pickup_address"
                                                        placeholder="Where should we pick up the 2nd vehicle?"
                                                        autocomplete="off" value="{{ old('vehicle_2_pickup_address') }}">
                                                    <div id="v2_pickup_suggestions" class="suggestions"></div>
                                                </div>
                                                <input type="hidden" id="v2_pickup_lat" name="vehicle_2_pickup_lat"
                                                    value="{{ old('vehicle_2_pickup_lat') }}">
                                                <input type="hidden" id="v2_pickup_lng" name="vehicle_2_pickup_lng"
                                                    value="{{ old('vehicle_2_pickup_lng') }}">
                                                @error('vehicle_2_pickup_address')
                                                    <span class="error-message">{{ $message }}</span>
                                                @enderror
                                            </div>
                                            <div class="form-group">
                                                <label for="v2_dropoff_address">Vehicle 2 Drop-off *</label>
                                                <div class="input-map-wrapper">
                                                    <input type="text" id="v2_dropoff_address"
                                                        name="vehicle_2_dropoff_address"
                                                        placeholder="Where is the 2nd vehicle headed?" autocomplete="off"
                                                        value="{{ old('vehicle_2_dropoff_address') }}">
                                                    <div id="v2_dropoff_suggestions" class="suggestions"></div>
                                                </div>

                                                <input type="hidden" id="v2_drop_lat" name="vehicle_2_drop_lat"
                                                    value="{{ old('vehicle_2_drop_lat') }}">
                                                <input type="hidden" id="v2_drop_lng" name="vehicle_2_drop_lng"
                                                    value="{{ old('vehicle_2_drop_lng') }}">
                                                @error('vehicle_2_dropoff_address')
                                                    <span class="error-message">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </div>
                                    </div>

                                    <input type="hidden" id="v2_distance_km" name="vehicle_2_distance_km"
                                        value="{{ old('vehicle_2_distance_km') }}">
                                    <input type="hidden" id="v2_eta_minutes" name="vehicle_2_eta_minutes"
                                        value="{{ old('vehicle_2_eta_minutes') }}">
                                    <input type="hidden" id="v2_price" name="vehicle_2_price"
                                        value="{{ old('vehicle_2_price') }}">

                                    <div id="v2_pricing_row"
                                        style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px;margin-top:6px;">
                                        <div>
                                            <div
                                                style="font-size:0.78rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">
                                                Vehicle 2 estimate
                                            </div>
                                            <div style="font-size:0.85rem;color:#111;margin-top:2px;">
                                                <span id="v2_distance_label">— km</span> ·
                                                <span id="v2_eta_label">— min</span>
                                            </div>
                                        </div>
                                        <div id="v2_price_label" style="font-size:1.05rem;font-weight:800;color:#111;">
                                            ₱0.00</div>
                                    </div>

                                    <div id="v2_schedule_notice"
                                        style="display:none;margin-top:10px;padding:10px 12px;border-radius:10px;background:#fef3c7;color:#92400e;font-size:0.8rem;font-weight:600;">
                                    </div>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="button" class="btn-secondary" onclick="history.back()">
                                    Back
                                </button>
                                <button type="button" class="btn-primary" id="submitBookingBtn">
                                    <span>Book Now</span>
                                </button>
                                <button type="button" class="btn-primary" id="scheduleBookingBtn"
                                    style="display:none;">
                                    <span>Schedule Booking</span>
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
        {{-- Google Maps is disabled for now while Leaflet is active.
        <script
            src="https://maps.googleapis.com/maps/api/js?key={{ urlencode(config('services.google_maps.key')) }}&libraries=places"
            defer></script>
        --}}
        <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
        <script src="{{ asset('customer/js/booking-debug.js') }}?v={{ time() }}"></script>
        <script>
            function setEtaHiddenField() {
                let eta = null;
                if (typeof currentEtaMinutes !== 'undefined') {
                    eta = currentEtaMinutes;
                } else if (typeof getPricingSnapshot === 'function') {
                    const snap = getPricingSnapshot();
                    if (snap && snap.etaMinutes !== undefined) {
                        eta = snap.etaMinutes;
                    }
                }
                if (!eta) {
                    const etaInput = document.getElementById('eta_minutes');
                    if (etaInput && etaInput.value) {
                        eta = etaInput.value;
                    }
                }
                const etaInput = document.getElementById('eta_minutes');
                if (etaInput) {
                    etaInput.value = eta && !isNaN(eta) ? Math.round(eta) : '';
                }
            }
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

                const truckClassVal = document.getElementById('truck_class_hidden')?.value;
                if (!truckClassVal) {
                    const firstCard = document.querySelector('.lf-class-card');
                    if (firstCard) {
                        firstCard.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                        firstCard.style.outline = '2px solid #dc2626';
                        setTimeout(() => firstCard.style.outline = '', 2000);
                    }
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
                if (!validateBookingForm()) {
                    return;
                }

                if (typeof window.checkBookNowAvailability === 'function') {
                    const canProceed = await window.checkBookNowAvailability();
                    if (!canProceed) return;
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

            function resolveTruckLabel() {
                const id = document.getElementById('truck_type_id')?.value;
                const card = id ? document.querySelector(`.lf-class-card[data-truck-id="${id}"]`) : null;
                if (!card) return 'Not selected';
                const name = card.querySelector('.lf-rate-row')?.previousElementSibling
                    ?.previousElementSibling?.textContent?.trim() ||
                    card.querySelectorAll('div')[1]?.textContent?.trim();
                const cls = card.dataset.class;
                const clsLbl = cls === 'light' ? 'Light' : cls === 'medium' ? 'Medium' : cls === 'heavy' ? 'Heavy' : '';
                return name ? `${name}${clsLbl ? ' (' + clsLbl + ')' : ''}` : 'Not selected';
            }

            function showConfirmationSummary() {
                confirmBookingBtn.disabled = true;
                bookingSummary.innerHTML = `
                    <div style="padding:48px 0;text-align:center;">
                        <div style="display:inline-block;width:28px;height:28px;border:3px solid #e5e7eb;border-top-color:#111827;border-radius:50%;animation:lf-spin 0.7s linear infinite;margin-bottom:14px;"></div>
                        <p style="margin:0;font-size:0.9rem;color:#6b7280;font-weight:500;">Calculating fare...</p>
                    </div>
                `;
                if (!document.getElementById('lf-spin-style')) {
                    const s = document.createElement('style');
                    s.id = 'lf-spin-style';
                    s.textContent = '@keyframes lf-spin{to{transform:rotate(360deg)}}';
                    document.head.appendChild(s);
                }
                confirmationModal.classList.add('modal-open');

                setTimeout(function() {
                    const pickup = document.getElementById('pickup_address').value;
                    const dropoff = document.getElementById('dropoff_address').value;
                    const customerVehicleType = document.getElementById('customerVehicleType').value;

                    const phone = document.getElementById('phone').value;
                    const email = document.getElementById('email').value;
                    const notes = document.getElementById('notes').value;
                    const serviceType = serviceTypeInput?.value === 'schedule' ? 'Scheduled Later' : 'Book Now';
                    const scheduleText = serviceTypeInput?.value === 'schedule' ?
                        `${scheduledDateInput?.value || 'N/A'} ${scheduledTimeInput?.value || ''}`.trim() :
                        'Immediate dispatch';

                    const fullName = [
                        document.getElementById('first_name').value,
                        document.getElementById('middle_name').value,
                        document.getElementById('last_name').value,
                    ].filter(Boolean).join(' ');
                    const pricingSnapshot = (typeof getPricingSnapshot === 'function') ? getPricingSnapshot() : {};
                    const _truckId = document.getElementById('truck_type_id')?.value;
                    const _rateData = _truckId && truckRates[_truckId] ? truckRates[_truckId] : null;
                    const _baseRateNum = _rateData ? Number(_rateData.base) : 0;
                    const baseRate = _baseRateNum > 0 ?
                        '₱' + _baseRateNum.toLocaleString('en-PH', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        }) :
                        (pricingSnapshot.baseRateText || '₱0.00');
                    const distance = pricingSnapshot.distanceText || '0 km';
                    const rate = pricingSnapshot.perKmRateText || '';
                    const distanceFee = pricingSnapshot.distanceFeeText || '₱0.00';
                    const _distanceFeeNum = pricingSnapshot.distanceFee != null ? Number(pricingSnapshot.distanceFee) :
                        parseFloat((distanceFee || '0').replace(/[^0-9.-]/g, '')) || 0;
                    const _discountNum = pricingSnapshot.discountAmount != null ? Number(pricingSnapshot
                        .discountAmount) : 0;
                    const discount = _discountNum > 0 ? ('₱' + _discountNum.toLocaleString('en-PH', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    })) : '';
                    const _computedTotal = _baseRateNum + _distanceFeeNum - _discountNum;
                    const total = '₱' + _computedTotal.toLocaleString('en-PH', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                    const truckLabel = resolveTruckLabel();

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
                            label: 'Truck Type',
                            value: truckLabel
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
                            label: 'Truck Type',
                            value: truckLabel
                        },
                        {
                            label: 'Base Rate',
                            value: baseRate
                        },
                        {
                            label: 'Distance',
                            value: distance
                        },
                        rate && rate !== '₱0.00' ? {
                            label: 'Per-4km Charge',
                            value: rate
                        } : null,
                        {
                            label: 'Distance Fee',
                            value: distanceFee
                        },
                        discount && discount !== '₱0.00' ? {
                            label: 'Discount',
                            value: discount
                        } : null,
                    ];

                    // ── 2nd vehicle summary (multi-tow) ────────────────────
                    let v2Section = '';
                    let v2GrandTotalNum = 0;
                    const v2Active = document.getElementById('add_second_vehicle')?.value === '1';
                    if (v2Active) {
                        const v2TruckId = document.getElementById('v2_truck_type_id')?.value;
                        const v2TruckCard = v2TruckId ? document.querySelector(
                            `.v2-class-card[data-truck-id="${v2TruckId}"]`) : null;
                        const v2TruckName = v2TruckCard ? v2TruckCard.querySelector('div[style*="font-weight:800"]')
                            ?.textContent?.trim() : 'Not selected';
                        const v2VehType = document.getElementById('v2_customer_vehicle_type')?.value || 'Not specified';
                        const v2Override = document.getElementById('v2_route_override')?.value === '1';
                        const v2Pickup = v2Override ? (document.getElementById('v2_pickup_address')?.value || pickup) :
                            pickup;
                        const v2Dropoff = v2Override ? (document.getElementById('v2_dropoff_address')?.value ||
                            dropoff) : dropoff;
                        const v2DistKm = parseFloat(document.getElementById('v2_distance_km')?.value || '0') || 0;
                        const v2EtaMin = parseFloat(document.getElementById('v2_eta_minutes')?.value || '0') || 0;
                        const v2PriceNum = parseFloat(document.getElementById('v2_price')?.value || '0') || 0;
                        v2GrandTotalNum = v2PriceNum;

                        const v2Items = [{
                                label: 'Truck Type',
                                value: v2TruckName
                            },
                            {
                                label: 'Customer Vehicle',
                                value: v2VehType
                            },
                            {
                                label: 'Pickup',
                                value: v2Pickup,
                                wide: true
                            },
                            {
                                label: 'Drop-off',
                                value: v2Dropoff,
                                wide: true
                            },
                            {
                                label: 'Distance',
                                value: v2DistKm ? v2DistKm.toFixed(2) + ' km' : '—'
                            },
                            {
                                label: 'ETA',
                                value: v2EtaMin ? Math.round(v2EtaMin) + ' min' : '—'
                            },
                            {
                                label: 'Vehicle 2 Estimate',
                                value: '₱' + v2PriceNum.toLocaleString('en-PH', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                })
                            },
                        ];
                        const noticeEl = document.getElementById('v2_schedule_notice');
                        const noticeNote = (noticeEl && noticeEl.style.display !== 'none') ? noticeEl.textContent
                            .trim() : '';
                        v2Section = renderSummarySection('Second Vehicle', v2Items, noticeNote ? {
                            helperNote: noticeNote
                        } : {});
                    }

                    const grandTotalNum = _computedTotal + v2GrandTotalNum;
                    const grandTotalStr = '₱' + grandTotalNum.toLocaleString('en-PH', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });

                    bookingSummary.innerHTML = `
                    <div class="summary-card">
                        ${renderSummarySection('Customer Information', customerItems)}
                        ${renderSummarySection('Trip Details', tripItems)}
                        ${renderSummarySection('Fare Summary', fareItems, {
                            totalValue: v2Active ? null : total,
                            helperNote: v2Active ? '' : 'Actual cost may vary according to different vehicle and booking mode'
                        })}
                        ${v2Section}
                        ${v2Active ? `
                                                    <div class="summary-section">
                                                        <div class="summary-section-title">Grand Total (Both Vehicles)</div>
                                                        <div class="summary-grid">
                                                            <div class="summary-total"><span>Estimated Total</span><h2>${grandTotalStr}</h2></div>
                                                        </div>
                                                        <p class="summary-helper-note">Actual cost may vary according to different vehicle and booking mode.</p>
                                                    </div>
                                                ` : ''}
                    </div>
                `;

                    confirmBookingBtn.disabled = false;
                }, 420);
            }

            confirmBookingBtn.addEventListener('click', function() {
                isConfirmed = true;
                confirmBookingBtn.disabled = true;
                confirmBookingBtn.textContent = 'Processing...';
                setEtaHiddenField();

                // Sync price_input with the computed total (base rate + distance fee - discount)
                const _syncTruckId = document.getElementById('truck_type_id')?.value;
                const _syncRateData = _syncTruckId && truckRates[_syncTruckId] ? truckRates[_syncTruckId] : null;
                const _syncBase = _syncRateData ? Number(_syncRateData.base) : 0;
                const _syncSnap = (typeof getPricingSnapshot === 'function') ? getPricingSnapshot() : {};
                const _syncDistFeeRaw = String(_syncSnap.distanceFeeText || '0').replace(/[^0-9.-]/g, '');
                const _syncDistFee = parseFloat(_syncDistFeeRaw) || 0;
                const _syncDiscount = parseFloat(String(_syncSnap.discountAmountText || '0').replace(/[^0-9.-]/g,
                    '')) || 0;
                const _syncTotal = _syncBase + _syncDistFee - _syncDiscount;
                const priceInput = document.getElementById('price_input');
                if (priceInput && _syncTotal > 0) {
                    priceInput.value = _syncTotal.toFixed(2);
                }

                const fd = new FormData(bookingForm);

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

        {{-- Truck Type Picker JS --}}
        <script>
            (function() {
                let selectedTruckId = '';
                let isScheduleMode = false;

                function selectCard(card) {
                    if (card.dataset.available !== '1' && !isScheduleMode) return;
                    document.querySelectorAll('.lf-class-card').forEach(c => {
                        c.style.background = '#fff';
                        c.style.borderColor = '#e5e7eb';
                        c.style.color = '#111827';
                        c.classList.remove('lf-selected');
                        const badge = c.querySelector('.lf-cls-badge');
                        if (badge) {
                            badge.style.background = badge.dataset.origBg || '';
                            badge.style.color = badge.dataset.origColor || '';
                        }
                        const avail = c.querySelector('.lf-avail-row');
                        if (avail) avail.style.color = '#6b7280';
                        const rate = c.querySelector('.lf-rate-row');
                        if (rate) rate.style.color = '#52525b';
                    });
                    card.style.background = '#111827';
                    card.style.borderColor = '#111827';
                    card.style.color = '#fff';
                    card.classList.add('lf-selected');
                    selectedTruckId = card.dataset.truckId || '';
                    document.getElementById('truck_type_id').value = selectedTruckId;
                    document.getElementById('truck_class_hidden').value = card.dataset.class || '';
                    document.dispatchEvent(new CustomEvent('v1ClassSelected', {
                        detail: {
                            cls: card.dataset.class || ''
                        }
                    }));
                    const badge = card.querySelector('.lf-cls-badge');
                    if (badge) {
                        badge.style.background = 'rgba(255,255,255,.15)';
                        badge.style.color = '#d4d4d8';
                    }
                    const avail = card.querySelector('.lf-avail-row');
                    if (avail) avail.style.color = '#d4d4d8';
                    const rate = card.querySelector('.lf-rate-row');
                    if (rate) rate.style.color = '#a1a1aa';
                }

                function updateCardAvailability() {
                    const msg = document.getElementById('lf_all_unavail');
                    let anyAvail = false;
                    document.querySelectorAll('.lf-class-card').forEach(card => {
                        const orig = card.dataset.available === '1';
                        if (isScheduleMode || orig) {
                            card.style.display = '';
                            card.style.opacity = '1';
                            card.style.cursor = 'pointer';
                            card.style.pointerEvents = '';
                            card.style.filter = '';
                            card.setAttribute('tabindex', '0');
                            card.setAttribute('aria-disabled', 'false');
                            anyAvail = true;
                        } else {
                            // Stay visible but greyed-out & unclickable in book_now
                            // when no unit of this class is available.
                            card.style.display = '';
                            card.style.opacity = '.45';
                            card.style.cursor = 'not-allowed';
                            card.style.pointerEvents = 'none';
                            card.style.filter = 'grayscale(70%)';
                            card.setAttribute('tabindex', '-1');
                            card.setAttribute('aria-disabled', 'true');
                            if (card.classList.contains('lf-selected')) {
                                card.style.background = '#fff';
                                card.style.borderColor = '#e5e7eb';
                                card.style.color = '#111827';
                                card.classList.remove('lf-selected');
                                selectedTruckId = '';
                                document.getElementById('truck_type_id').value = '';
                                document.getElementById('truck_class_hidden').value = '';
                            }
                        }
                    });
                    if (msg) msg.style.display = (!anyAvail && !isScheduleMode) ? 'block' : 'none';
                }

                document.addEventListener('DOMContentLoaded', function() {
                    document.querySelectorAll('.lf-cls-badge').forEach(b => {
                        b.dataset.origBg = b.style.background;
                        b.dataset.origColor = b.style.color;
                    });

                    document.querySelectorAll('.lf-class-card').forEach(card => {
                        card.addEventListener('click', () => selectCard(card));
                        card.addEventListener('keydown', e => {
                            if (e.key === 'Enter' || e.key === ' ') {
                                e.preventDefault();
                                selectCard(card);
                            }
                        });
                    });

                    const oldId = document.getElementById('truck_type_id').value;
                    if (oldId) {
                        const existing = document.querySelector(`.lf-class-card[data-truck-id="${oldId}"]`);
                        if (existing) selectCard(existing);
                    }

                    const svcSel = document.getElementById('service_type');
                    svcSel && svcSel.addEventListener('change', () => {
                        isScheduleMode = svcSel.value === 'schedule';
                        updateCardAvailability();
                    });

                    updateCardAvailability();
                });
            })();
        </script>

        {{-- ── Vehicle 2 (multi-tow) JS ─────────────────────────────────── --}}
        <script>
            (function() {
                const wrapper = document.getElementById('v2_wrapper');
                if (!wrapper) return;

                const toggleBtn = document.getElementById('v2_toggle_btn');
                const panel = document.getElementById('v2_panel');
                const flagInput = document.getElementById('add_second_vehicle');
                const truckInput = document.getElementById('v2_truck_type_id');
                const overrideChk = document.getElementById('v2_route_override_chk');
                const overrideInput = document.getElementById('v2_route_override');
                const routeFields = document.getElementById('v2_route_fields');
                const v2Pickup = document.getElementById('v2_pickup_address');
                const v2Dropoff = document.getElementById('v2_dropoff_address');
                const v2PickupLat = document.getElementById('v2_pickup_lat');
                const v2PickupLng = document.getElementById('v2_pickup_lng');
                const v2DropLat = document.getElementById('v2_drop_lat');
                const v2DropLng = document.getElementById('v2_drop_lng');
                const v2DistanceIn = document.getElementById('v2_distance_km');
                const v2EtaIn = document.getElementById('v2_eta_minutes');
                const v2PriceIn = document.getElementById('v2_price');
                const v2DistLabel = document.getElementById('v2_distance_label');
                const v2EtaLabel = document.getElementById('v2_eta_label');
                const v2PriceLabel = document.getElementById('v2_price_label');
                const v2Notice = document.getElementById('v2_schedule_notice');
                const pickupSugg = document.getElementById('v2_pickup_suggestions');
                const dropoffSugg = document.getElementById('v2_dropoff_suggestions');

                function pesos(n) {
                    return '₱' + Number(n || 0).toLocaleString('en-PH', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                }

                function setOpen(open) {
                    panel.style.display = open ? 'block' : 'none';
                    flagInput.value = open ? '1' : '0';
                    toggleBtn.textContent = open ? '× Remove second vehicle' : '+ Add another vehicle';
                    if (!open) {
                        truckInput.value = '';
                        document.querySelectorAll('.v2-class-card.v2-selected').forEach(c => c.click());
                        v2Notice.style.display = 'none';
                    }
                    recompute();
                }

                toggleBtn.addEventListener('click', () => setOpen(panel.style.display === 'none'));

                // ── Grey-out unavailable Vehicle 2 truck cards in book_now mode ──
                function syncV2CardVisibility() {
                    const isSchedule = (document.getElementById('service_type')?.value || 'book_now') === 'schedule';
                    document.querySelectorAll('.v2-class-card').forEach(card => {
                        const avail = card.dataset.available === '1';
                        if (isSchedule || avail) {
                            card.style.opacity = '1';
                            card.style.cursor = 'pointer';
                            card.style.pointerEvents = '';
                            card.style.filter = '';
                            card.setAttribute('aria-disabled', 'false');
                            card.setAttribute('tabindex', '0');
                        } else {
                            card.style.opacity = '.45';
                            card.style.cursor = 'not-allowed';
                            card.style.pointerEvents = 'none';
                            card.style.filter = 'grayscale(70%)';
                            card.setAttribute('aria-disabled', 'true');
                            card.setAttribute('tabindex', '-1');
                            if (card.classList.contains('v2-selected')) {
                                card.classList.remove('v2-selected');
                                card.style.background = '#fff';
                                card.style.borderColor = '#e5e7eb';
                                card.style.color = '#111827';
                                truckInput.value = '';
                            }
                        }
                    });
                }
                syncV2CardVisibility();
                document.getElementById('service_type')?.addEventListener('change', function() {
                    syncV2CardVisibility();
                    syncV2ClassLock(v1LockedClass);
                });

                // ── Lock V2: prevent selecting same class as V1 ────────
                let v1LockedClass = '';

                function syncV2ClassLock(cls) {
                    if (cls != null) v1LockedClass = cls;
                    document.querySelectorAll('.v2-class-card').forEach(card => {
                        if (card.dataset.class === v1LockedClass && v1LockedClass !== '') {
                            card.style.opacity = '.35';
                            card.style.cursor = 'not-allowed';
                            card.style.pointerEvents = 'none';
                            card.style.filter = 'grayscale(80%)';
                            card.setAttribute('aria-disabled', 'true');
                            card.setAttribute('tabindex', '-1');
                            if (card.classList.contains('v2-selected')) {
                                card.style.background = '#fff';
                                card.style.borderColor = '#e5e7eb';
                                card.style.color = '#111827';
                                card.classList.remove('v2-selected');
                                truckInput.value = '';
                                recompute();
                            }
                        }
                    });
                }
                // Listen for V1 class selection
                document.addEventListener('v1ClassSelected', function(e) {
                    syncV2CardVisibility();
                    syncV2ClassLock(e.detail?.cls || '');
                });
                // Seed on page load if V1 already has a card selected
                (function() {
                    const sel = document.querySelector('.lf-class-card.lf-selected');
                    if (sel) syncV2ClassLock(sel.dataset.class || '');
                })();

                // ── Class picker (Vehicle 2) ───────────────────────────
                document.querySelectorAll('.v2-class-card').forEach(card => {
                    card.addEventListener('click', () => {
                        if (card.getAttribute('aria-disabled') === 'true') return;
                        document.querySelectorAll('.v2-class-card').forEach(c => {
                            c.style.background = '#fff';
                            c.style.borderColor = '#e5e7eb';
                            c.style.color = '#111827';
                            c.classList.remove('v2-selected');
                        });
                        card.style.background = '#111827';
                        card.style.borderColor = '#111827';
                        card.style.color = '#fff';
                        card.classList.add('v2-selected');
                        truckInput.value = card.dataset.truckId || '';
                        recompute();
                        evaluateAvailabilityNotice();
                    });
                });

                // Restore old() selection if any
                const preselectedId = truckInput.value;
                if (preselectedId) {
                    const c = document.querySelector(`.v2-class-card[data-truck-id="${preselectedId}"]`);
                    if (c) c.click();
                }

                // ── Route override toggle ──────────────────────────────
                function syncOverride() {
                    const on = !!overrideChk.checked;
                    overrideInput.value = on ? '1' : '0';
                    routeFields.style.display = on ? 'block' : 'none';
                    recompute();
                }
                overrideChk.addEventListener('change', syncOverride);

                // ── Suggestions wiring (mirrors map.js getSuggestions) ─
                function escHtml(str) {
                    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                }

                function attachSuggest(inputEl, suggBox, latEl, lngEl) {
                    if (!inputEl || !suggBox) return;
                    let timer = null;
                    let loadingTimer = null;

                    inputEl.addEventListener('input', () => {
                        const q = inputEl.value.trim();
                        latEl.value = '';
                        lngEl.value = '';
                        v2DistanceIn.value = '';
                        v2EtaIn.value = '';
                        recompute();

                        clearTimeout(timer);
                        clearTimeout(loadingTimer);
                        if (q.length < 2) {
                            suggBox.innerHTML = '';
                            suggBox.style.display = 'none';
                            return;
                        }

                        timer = setTimeout(async () => {
                            loadingTimer = setTimeout(() => {
                                suggBox.innerHTML =
                                    '<div class="suggestion-loading">Loading</div>';
                                suggBox.style.display = 'block';
                            }, 400);

                            try {
                                const res = await fetch(window.bookingGeoConfig.searchUrl + '?q=' +
                                    encodeURIComponent(q), {
                                        headers: {
                                            Accept: 'application/json',
                                            'X-Requested-With': 'XMLHttpRequest'
                                        },
                                        credentials: 'same-origin',
                                    });
                                clearTimeout(loadingTimer);
                                const data = await res.json();
                                const features = (data && data.features) || [];

                                suggBox.innerHTML = '';
                                if (features.length === 0) {
                                    suggBox.innerHTML =
                                        '<div class="suggestion-empty">No results found. Try adding city or landmark.</div>';
                                    suggBox.style.display = 'block';
                                    return;
                                }

                                features.forEach((place) => {
                                    const label = (place.label || '').trim();
                                    const commaIdx = label.indexOf(',');
                                    const primary = commaIdx > -1 ? label.substring(0, commaIdx)
                                        .trim() :
                                        label;
                                    const secondary = commaIdx > -1 ? label.substring(commaIdx +
                                            1).trim() :
                                        '';
                                    const coords = place.coordinates || [];

                                    const row = document.createElement('div');
                                    row.className = 'suggestion-row';
                                    row.innerHTML =
                                        '<span class="suggestion-body">' +
                                        '<span class="suggestion-primary">' + escHtml(primary) +
                                        '</span>' +
                                        (secondary ? '<span class="suggestion-secondary">' +
                                            escHtml(
                                                secondary) + '</span>' : '') +
                                        '</span>';

                                    row.addEventListener('click', () => {
                                        inputEl.value = label;
                                        latEl.value = coords[1] != null ? coords[1] :
                                            '';
                                        lngEl.value = coords[0] != null ? coords[0] :
                                            '';
                                        suggBox.innerHTML = '';
                                        suggBox.style.display = 'none';
                                        fetchRouteDistance();
                                    });

                                    suggBox.appendChild(row);
                                });
                                suggBox.style.display = 'block';
                            } catch (_) {
                                clearTimeout(loadingTimer);
                                suggBox.innerHTML = '';
                                suggBox.style.display = 'none';
                            }
                        }, 250);
                    });

                    document.addEventListener('click', (ev) => {
                        if (!suggBox.contains(ev.target) && ev.target !== inputEl) {
                            suggBox.style.display = 'none';
                        }
                    });
                }
                attachSuggest(v2Pickup, pickupSugg, v2PickupLat, v2PickupLng);
                attachSuggest(v2Dropoff, dropoffSugg, v2DropLat, v2DropLng);

                // ── Fetch route distance for vehicle 2 (override mode) ─
                let routeAbort = null;
                async function fetchRouteDistance() {
                    if (overrideInput.value !== '1') return;
                    const a = v2PickupLat.value,
                        b = v2PickupLng.value,
                        c = v2DropLat.value,
                        d = v2DropLng.value;
                    if (!a || !b || !c || !d) return;
                    try {
                        if (routeAbort) routeAbort.abort();
                        routeAbort = new AbortController();
                        const url = window.bookingGeoConfig.routeUrl +
                            `?from_lat=${a}&from_lng=${b}&to_lat=${c}&to_lng=${d}`;
                        const res = await fetch(url, {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            credentials: 'same-origin',
                            signal: routeAbort.signal,
                        });
                        const data = await res.json();
                        const distKm = Number(data.distance_km || data.distance || 0);
                        const etaMin = Number(data.duration_min || data.eta_minutes || 0);
                        if (distKm > 0) {
                            v2DistanceIn.value = distKm.toFixed(2);
                            v2EtaIn.value = Math.round(etaMin);
                            recompute();
                        }
                    } catch (e) {
                        /* aborted/silent */
                    }
                }

                // ── Recompute pricing/eta display ──────────────────────
                function recompute() {
                    if (panel.style.display === 'none') return;
                    const truckId = truckInput.value;
                    const rate = (truckId && truckRates[truckId]) ? truckRates[truckId] : null;
                    if (!rate) {
                        v2DistLabel.textContent = '— km';
                        v2EtaLabel.textContent = '— min';
                        v2PriceLabel.textContent = pesos(0);
                        v2PriceIn.value = '';
                        return;
                    }

                    let distKm, etaMin;
                    if (overrideInput.value === '1') {
                        distKm = parseFloat(v2DistanceIn.value || '0') || 0;
                        etaMin = parseFloat(v2EtaIn.value || '0') || 0;
                    } else {
                        distKm = parseFloat(document.getElementById('distance_input').value || '0') || 0;
                        etaMin = parseFloat(document.getElementById('eta_minutes').value || '0') || 0;
                        v2DistanceIn.value = distKm ? distKm.toFixed(2) : '';
                        v2EtaIn.value = etaMin ? Math.round(etaMin) : '';
                    }

                    const kmIncrements = Math.floor(distKm / 4);
                    const distanceFee = kmIncrements * 200;
                    const total = Number(rate.base) + distanceFee;

                    v2DistLabel.textContent = (distKm ? distKm.toFixed(2) : '—') + ' km';
                    v2EtaLabel.textContent = (etaMin ? Math.round(etaMin) : '—') + ' min';
                    v2PriceLabel.textContent = pesos(total);
                    v2PriceIn.value = total.toFixed(2);
                }

                // ── Availability notice (1 unit → 2nd auto-scheduled) ─
                function evaluateAvailabilityNotice() {
                    if (panel.style.display === 'none' || !truckInput.value) {
                        v2Notice.style.display = 'none';
                        return;
                    }
                    const svc = document.getElementById('service_type')?.value || 'book_now';
                    if (svc !== 'book_now') {
                        v2Notice.style.display = 'none';
                        return;
                    }

                    let totalAvail = 0;
                    document.querySelectorAll('.lf-class-card').forEach(c => {
                        const n = parseInt(c.querySelector('.lf-avail-row')?.textContent?.match(/\d+/)?.[0] || '0',
                            10);
                        if (c.dataset.available === '1') totalAvail += n;
                    });

                    if (totalAvail < 2) {
                        v2Notice.textContent = totalAvail === 1 ?
                            'Heads up: only 1 unit is online right now. Your 2nd vehicle will be auto-scheduled for the next hour.' :
                            'No units online right now — both vehicles will be scheduled.';
                        v2Notice.style.display = 'block';
                    } else {
                        v2Notice.style.display = 'none';
                    }
                }

                // Recompute when the main route updates
                ['distance_input', 'eta_minutes'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) {
                        new MutationObserver(recompute).observe(el, {
                            attributes: true,
                            attributeFilter: ['value']
                        });
                        el.addEventListener('change', recompute);
                    }
                });
                document.getElementById('service_type')?.addEventListener('change', evaluateAvailabilityNotice);

                // Initial paint
                syncOverride();
                recompute();
                evaluateAvailabilityNotice();

                // Also recompute when any v2 hidden distance changes externally
                setInterval(recompute, 1500);
            })();
        </script>
    @endpush
@endsection
