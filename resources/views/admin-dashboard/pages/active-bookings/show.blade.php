@extends('admin-dashboard.layouts.app')

@section('title', 'Booking Details - ' . $booking->job_code)

@section('content')
    <style>
        .booking-detail-hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 24px;
            border-radius: 16px;
            margin-bottom: 32px;
            box-shadow: 0 20px 60px rgba(102, 126, 234, 0.2);
        }

        .booking-detail-hero h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 800;
        }

        .booking-detail-hero p {
            margin: 8px 0 0 0;
            opacity: 0.95;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .detail-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
        }

        .detail-card h3 {
            margin: 0 0 16px 0;
            font-size: 1.1rem;
            color: #0f172a;
            border-bottom: 2px solid #f0f4ff;
            padding-bottom: 12px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e5e7eb;
        }

        .detail-row:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: #0f172a;
            font-size: 0.9rem;
        }

        .detail-value {
            color: #334155;
            text-align: right;
            flex: 1;
            margin-left: 16px;
        }

        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.85rem;
        }

        .status-badge.in_progress {
            background: #dcfce7;
            color: #166534;
        }

        .status-badge.on_way {
            background: #fed7aa;
            color: #92400e;
        }

        .status-badge.on_job {
            background: #fbcfe8;
            color: #be185d;
        }

        .status-badge.accepted {
            background: #dbeafe;
            color: #0369a1;
        }

        .status-badge.assigned {
            background: #fef3c7;
            color: #92400e;
        }

        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        .btn {
            flex: 1;
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.3);
        }

        .btn-secondary {
            background: #e5e7eb;
            color: #0f172a;
            border: 1px solid #d1d5db;
        }

        .btn-secondary:hover {
            background: #d1d5db;
        }

        .timeline {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
        }

        .timeline h3 {
            margin: 0 0 20px 0;
            color: #0f172a;
        }

        .timeline-item {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
        }

        .timeline-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .timeline-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #f0f4ff;
            color: #667eea;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .timeline-content {
            flex: 1;
        }

        .timeline-label {
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 4px;
        }

        .timeline-time {
            font-size: 0.85rem;
            color: #64748b;
        }

        @media (max-width: 768px) {
            .booking-detail-hero h1 {
                font-size: 1.5rem;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }
        }
    </style>

    <div class="booking-detail-container">
        <a href="{{ route('admin.active-bookings.index') }}"
            style="color: #667eea; text-decoration: none; margin-bottom: 16px; display: inline-block;">
            ← Back to Active Bookings
        </a>

        <div class="booking-detail-hero">
            <h1>Booking #{{ $booking->job_code }}</h1>
            <p>Reference for tracking and management</p>
        </div>

        <div class="detail-grid">
            <!-- Customer Card -->
            <div class="detail-card">
                <h3>👤 Customer Details</h3>
                <div class="detail-row">
                    <span class="detail-label">Name</span>
                    <span class="detail-value">{{ $booking->customer->full_name ?? 'Guest' }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Phone</span>
                    <span class="detail-value">{{ $booking->customer->phone ?? 'N/A' }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Email</span>
                    <span class="detail-value">{{ $booking->customer->email ?? 'N/A' }}</span>
                </div>
            </div>

            <!-- Booking Status Card -->
            <div class="detail-card">
                <h3>📊 Booking Status</h3>
                <div class="detail-row">
                    <span class="detail-label">Status</span>
                    <span class="detail-value">
                        <span class="status-badge {{ $booking->status }}">
                            {{ str_replace('_', ' ', strtoupper($booking->status)) }}
                        </span>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Created</span>
                    <span class="detail-value">{{ $booking->created_at->format('M d, Y H:i') }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Updated</span>
                    <span class="detail-value">{{ $booking->updated_at->format('M d, Y H:i') }}</span>
                </div>
            </div>

            <!-- Assignment Card -->
            <div class="detail-card">
                <h3>🚗 Assignment</h3>
                <div class="detail-row">
                    <span class="detail-label">Unit</span>
                    <span class="detail-value">{{ $booking->unit?->name ?? 'Unassigned' }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Team Leader</span>
                    <span class="detail-value">{{ $booking->unit?->teamLeader?->full_name ?? 'N/A' }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Zone</span>
                    <span class="detail-value">{{ $booking->unit?->zone?->name ?? 'No Zone' }}</span>
                </div>
            </div>

            <!-- Route Card -->
            <div class="detail-card">
                <h3>📍 Route</h3>
                <div class="detail-row">
                    <span class="detail-label">Pickup</span>
                    <span class="detail-value">{{ Str::limit($booking->pickup_address ?? 'Unknown', 40) }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Dropoff</span>
                    <span class="detail-value">{{ Str::limit($booking->dropoff_address ?? 'Unknown', 40) }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Distance</span>
                    <span class="detail-value">{{ $booking->distance_km ?? '0' }} km</span>
                </div>
            </div>

            <!-- Service Card -->
            <div class="detail-card">
                <h3>🛠️ Service</h3>
                <div class="detail-row">
                    <span class="detail-label">Truck Type</span>
                    <span class="detail-value">{{ $booking->truckType?->name ?? 'Unknown' }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Base Rate</span>
                    <span class="detail-value">₱{{ number_format((float) ($booking->base_rate ?? 0), 2) }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Distance Fee</span>
                    <span class="detail-value">₱{{ number_format((float) ($booking->distance_fee_amount ?? 0), 2) }}</span>
                </div>
            </div>

            <!-- Pricing Card -->
            <div class="detail-card">
                <h3>💰 Pricing</h3>
                <div class="detail-row">
                    <span class="detail-label">Additional Fee</span>
                    <span class="detail-value">₱{{ number_format((float) ($booking->additional_fee ?? 0), 2) }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Discount (%)</span>
                    <span class="detail-value">{{ number_format((float) ($booking->discount_percentage ?? 0), 2) }}%</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Final Total</span>
                    <span class="detail-value" style="font-weight: 700; color: #667eea; font-size: 1.1rem;">
                        ₱{{ number_format((float) $booking->final_total, 2) }}
                    </span>
                </div>
            </div>

            <!-- Notes Card -->
            <div class="detail-card">
                <h3>📝 Notes</h3>
                <div class="detail-row">
                    <span class="detail-label">Dispatcher Note</span>
                    <span class="detail-value">{{ $booking->dispatcher_note ?? '—' }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Remarks</span>
                    <span class="detail-value">{{ $booking->remarks ?? '—' }}</span>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div
            style="background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);">
            <h3 style="margin: 0 0 16px 0; color: #0f172a;">Actions</h3>
            <div class="action-buttons">
                <a href="{{ route('admin.active-bookings.index') }}" class="btn btn-secondary">← Back to List</a>
                <button type="button" class="btn btn-primary" onclick="openEditModal()">✏️ Edit Booking</button>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="hidden"
        style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div
            style="background: white; border-radius: 12px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; padding: 32px;">
            <h2 style="margin-top: 0; color: #0f172a;">Edit Booking</h2>
            <form id="editForm" method="POST" action="{{ route('admin.active-bookings.update-route', $booking) }}">
                @csrf
                @method('PATCH')

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #0f172a;">Status</label>
                    <select id="statusSelect" name="status"
                        style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.95rem;">
                        <option value="accepted" {{ $booking->status === 'accepted' ? 'selected' : '' }}>Accepted</option>
                        <option value="assigned" {{ $booking->status === 'assigned' ? 'selected' : '' }}>Assigned</option>
                        <option value="confirmed" {{ $booking->status === 'confirmed' ? 'selected' : '' }}>Confirmed
                        </option>
                        <option value="in_progress" {{ $booking->status === 'in_progress' ? 'selected' : '' }}>In Progress
                        </option>
                        <option value="on_way" {{ $booking->status === 'on_way' ? 'selected' : '' }}>On Way</option>
                        <option value="on_job" {{ $booking->status === 'on_job' ? 'selected' : '' }}>On Job</option>
                        <option value="on_tow" {{ $booking->status === 'on_tow' ? 'selected' : '' }}>On Tow</option>
                        <option value="completed" {{ $booking->status === 'completed' ? 'selected' : '' }}>Completed
                        </option>
                    </select>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #0f172a;">Pickup
                        Address</label>
                    <input type="text" name="pickup_address" value="{{ $booking->pickup_address }}"
                        style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.95rem;">
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #0f172a;">Dropoff
                        Address</label>
                    <input type="text" name="dropoff_address" value="{{ $booking->dropoff_address }}"
                        style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.95rem;">
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #0f172a;">Dispatcher
                        Note</label>
                    <textarea name="dispatcher_note" rows="3"
                        style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.95rem;">{{ $booking->dispatcher_note }}</textarea>
                </div>

                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="button"
                        style="flex: 1; padding: 12px; border: none; border-radius: 8px; background: #e5e7eb; color: #0f172a; font-weight: 600; cursor: pointer;"
                        onclick="closeEditModal()">
                        Cancel
                    </button>
                    <button type="submit"
                        style="flex: 1; padding: 12px; border: none; border-radius: 8px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; font-weight: 600; cursor: pointer;">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEditModal() {
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        document.getElementById('editModal').addEventListener('click', (e) => {
            if (e.target.id === 'editModal') {
                closeEditModal();
            }
        });
    </script>
@endsection
