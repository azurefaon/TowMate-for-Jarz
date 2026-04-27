@extends('admin-dashboard.layouts.app')

@section('title', 'Active Bookings - Live Tracking')

@section('content')
    <style>
        .active-bookings-hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 48px 24px;
            border-radius: 16px;
            margin-bottom: 40px;
            box-shadow: 0 20px 60px rgba(102, 126, 234, 0.2);
        }

        .active-bookings-hero h1 {
            margin: 0 0 12px 0;
            font-size: 2.5rem;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .active-bookings-hero p {
            margin: 0;
            font-size: 1.1rem;
            opacity: 0.95;
        }

        .bookings-table-wrapper {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }

        .bookings-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }

        .bookings-table thead {
            background: #f8fafc;
            border-bottom: 2px solid #e5e7eb;
        }

        .bookings-table th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: #0f172a;
        }

        .bookings-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
            transition: background 0.2s ease;
        }

        .bookings-table tbody tr:hover {
            background: #f8fafc;
        }

        .bookings-table td {
            padding: 14px 16px;
            color: #334155;
        }

        .job-code {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            color: #667eea;
        }

        .customer-name {
            font-weight: 600;
            color: #0f172a;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.accepted {
            background: #dbeafe;
            color: #0369a1;
        }

        .status-badge.assigned {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.confirmed {
            background: #c7d2fe;
            color: #3730a3;
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

        .status-badge.on_tow {
            background: #fda29b;
            color: #7c2d12;
        }

        .team-leader-pill {
            display: inline-block;
            background: #e5e7eb;
            color: #0f172a;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.85rem;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-small {
            padding: 6px 12px;
            border-radius: 6px;
            border: none;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-view {
            background: #f0f4ff;
            color: #667eea;
            border: 1px solid #dbeafe;
        }

        .btn-view:hover {
            background: #e0e9ff;
            border-color: #667eea;
        }

        .btn-edit {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
        }

        .btn-edit:hover {
            background: #fde68a;
            border-color: #92400e;
        }

        .empty-state {
            text-align: center;
            padding: 60px 40px;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-radius: 12px;
            border: 2px dashed #cbd5e1;
        }

        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 16px;
        }

        .empty-state h3 {
            margin: 0 0 8px 0;
            color: #0f172a;
            font-size: 1.25rem;
        }

        .empty-state p {
            margin: 0;
            color: #64748b;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: #667eea;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #64748b;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .active-bookings-hero {
                padding: 32px 16px;
            }

            .active-bookings-hero h1 {
                font-size: 1.75rem;
            }

            .bookings-table {
                font-size: 0.85rem;
            }

            .bookings-table th,
            .bookings-table td {
                padding: 10px 8px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn-small {
                width: 100%;
                justify-content: center;
            }
        }
    </style>

    <div class="active-bookings-container">
        <div class="active-bookings-hero">
            <h1>🚗 Active Bookings</h1>
            <p>Live tracking and real-time booking management</p>
        </div>

        @if (session('success'))
            <div
                style="background: #f0fdf4; color: #166534; padding: 16px 20px; border-radius: 8px; margin-bottom: 24px; border-left: 4px solid #22c55e;">
                <strong>✓ Success!</strong> {{ session('success') }}
            </div>
        @endif

        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-value">{{ $activeBookings->total() }}</div>
                <div class="stat-label">Total Active</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">
                    @php
                        $inProgress = $activeBookings
                            ->filter(fn($b) => in_array($b->status, ['in_progress', 'on_way', 'on_job', 'on_tow']))
                            ->count();
                    @endphp
                    {{ $inProgress }}
                </div>
                <div class="stat-label">In Progress</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">
                    @php
                        $pending = $activeBookings
                            ->filter(fn($b) => in_array($b->status, ['assigned', 'confirmed', 'accepted']))
                            ->count();
                    @endphp
                    {{ $pending }}
                </div>
                <div class="stat-label">Pending Start</div>
            </div>
        </div>

        @forelse ($activeBookings as $booking)
            @if ($loop->first)
                <div class="bookings-table-wrapper">
                    <table class="bookings-table">
                        <thead>
                            <tr>
                                <th style="width: 10%;">Booking #</th>
                                <th style="width: 15%;">Customer</th>
                                <th style="width: 15%;">Pickup → Dropoff</th>
                                <th style="width: 12%;">Team Leader</th>
                                <th style="width: 12%;">Status</th>
                                <th style="width: 12%;">Total</th>
                                <th style="width: 24%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
            @endif

            <tr>
                <td>
                    <span class="job-code">{{ $booking->job_code }}</span>
                </td>
                <td>
                    <div class="customer-name">{{ $booking->customer->full_name ?? 'Guest' }}</div>
                    <div style="font-size: 0.85rem; color: #64748b;">{{ $booking->customer->phone ?? 'N/A' }}</div>
                </td>
                <td>
                    <div style="font-size: 0.9rem; color: #334155;">
                        <strong></strong> {{ Str::limit($booking->pickup_address ?? 'Unknown', 25) }}
                    </div>
                    <div style="font-size: 0.85rem; color: #64748b;">
                        → {{ Str::limit($booking->dropoff_address ?? 'Unknown', 25) }}
                    </div>
                </td>
                <td>
                    @if ($booking->unit?->teamLeader)
                        <span class="team-leader-pill">
                            {{ $booking->unit->teamLeader->full_name ?? $booking->unit->teamLeader->name }}
                        </span>
                        @if ($booking->unit?->zone)
                            <div style="font-size: 0.85rem; color: #64748b; margin-top: 4px;">
                                📍 {{ $booking->unit->zone->name }}
                            </div>
                        @endif
                    @else
                        <span style="color: #94a3b8;">Unassigned</span>
                    @endif
                </td>
                <td>
                    <span class="status-badge {{ $booking->status }}">
                        {{ str_replace('_', ' ', $booking->status) }}
                    </span>
                </td>
                <td>
                    <strong>₱{{ number_format((float) $booking->final_total, 2) }}</strong>
                </td>
                <td>
                    <div class="action-buttons">
                        <a href="{{ route('admin.active-bookings.show', $booking) }}" class="btn-small btn-view">
                            👁️ View
                        </a>
                        <button type="button" class="btn-small btn-edit" onclick="editBooking({{ $booking->id }})">
                            ✏️ Edit
                        </button>
                    </div>
                </td>
            </tr>

            @if ($loop->last)
                </tbody>
                </table>
    </div>

    @if ($activeBookings->hasPages())
        <div style="margin-top: 32px;">
            {{ $activeBookings->links() }}
        </div>
    @endif
    @endif
@empty
    <div class="empty-state">
        <div class="empty-state-icon">📭</div>
        <h3>No Active Bookings</h3>
        <p>There are currently no active bookings. Check back soon!</p>
    </div>
    @endforelse
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="hidden"
        style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div
            style="background: white; border-radius: 12px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; padding: 32px;">
            <h2 style="margin-top: 0; color: #0f172a;">Edit Booking</h2>
            <form id="editForm" method="POST">
                @csrf
                @method('PATCH')

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #0f172a;">Status</label>
                    <select id="statusSelect" name="status"
                        style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.95rem;">
                        <option value="accepted">Accepted</option>
                        <option value="assigned">Assigned</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="in_progress">In Progress</option>
                        <option value="on_way">On Way</option>
                        <option value="on_job">On Job</option>
                        <option value="on_tow">On Tow</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #0f172a;">Pickup
                        Address</label>
                    <input type="text" id="pickupInput" name="pickup_address"
                        style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.95rem;">
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #0f172a;">Dropoff
                        Address</label>
                    <input type="text" id="dropoffInput" name="dropoff_address"
                        style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.95rem;">
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #0f172a;">Dispatcher
                        Note</label>
                    <textarea id="noteInput" name="dispatcher_note" rows="3"
                        style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.95rem;"></textarea>
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
        function editBooking(bookingId) {
            const modal = document.getElementById('editModal');
            const form = document.getElementById('editForm');
            form.action = `/admin-dashboard/active-bookings/${bookingId}/route`;
            modal.style.display = 'flex';

            // Fetch booking data via AJAX
            fetch(`/admin-dashboard/active-bookings/${bookingId}?json=1`)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.booking) {
                        const booking = data.booking;
                        document.getElementById('statusSelect').value = booking.status;
                        document.getElementById('pickupInput').value = booking.pickup_address || '';
                        document.getElementById('dropoffInput').value = booking.dropoff_address || '';
                        document.getElementById('noteInput').value = booking.dispatcher_note || '';
                    }
                })
                .catch(e => console.error('Error:', e));
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        document.getElementById('editForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);

            try {
                const response = await fetch(form.action, {
                    method: form.method,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    },
                    body: formData,
                });

                const result = await response.json();
                if (result.success) {
                    closeEditModal();
                    location.reload();
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            }
        });

        // Close modal when clicking outside
        document.getElementById('editModal').addEventListener('click', (e) => {
            if (e.target.id === 'editModal') {
                closeEditModal();
            }
        });
    </script>
@endsection
