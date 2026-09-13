@extends('layouts.superadmin')

@section('title', 'Personnel')

@push('styles')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="{{ asset('admin/css/personnel.css') }}?v={{ filemtime(public_path('admin/css/personnel.css')) }}">
@endpush

@section('content')
    <div class="personnel-page" data-base-url="{{ url('/superadmin/personnel-records') }}">
        <div class="page-top">
            <div>
                <h1>Personnel</h1>
                <p>Master Driver and Pahinante/Crew records, Team Leader Home Unit assignment, and normal roster placement.</p>
            </div>
        </div>

        @if (session('success'))
            <div class="type-feedback type-feedback--success" id="personnelSuccessAlert">{{ session('success') }}</div>
        @endif

        @include('superadmin.fleet._tabs')

        <div class="pp-toolbar">
            <button type="button" class="pp-add-btn" id="ppAddBtn">Add Personnel</button>
        </div>

        <div class="table-card">
            <div class="table-scroll">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Personnel</th>
                            <th>Role</th>
                            <th>Home Unit</th>
                            <th>Availability</th>
                            <th>Status</th>
                            <th class="u-actions-col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($personnel as $row)
                            <tr>
                                <td data-label="Personnel">
                                    <span class="cell-main">{{ $row['full_name'] }}</span>
                                    @if ($row['reference'])
                                        <span class="cell-sub">{{ $row['reference'] }}</span>
                                    @else
                                        <span class="cell-sub">Personnel record</span>
                                    @endif
                                </td>

                                <td data-label="Role">{{ $row['role_label'] }}</td>

                                <td data-label="Home Unit">
                                    <span class="cell-main">{{ $row['home_unit']->name ?? 'Not set' }}</span>
                                    @if ($row['borrowed'])
                                        <span class="cell-sub">Currently: {{ $row['current_unit']->name }} (Borrowed)</span>
                                    @elseif ($row['current_unit'] && (! $row['home_unit'] || $row['current_unit']->id !== $row['home_unit']->id))
                                        <span class="cell-sub">Currently: {{ $row['current_unit']->name }}</span>
                                    @endif
                                </td>

                                <td data-label="Availability">
                                    <span class="status-text status-{{ strtolower($row['availability']) }}">{{ $row['availability'] }}</span>
                                </td>

                                <td data-label="Status">
                                    <span class="status-text status-{{ strtolower($row['status']) }}">{{ $row['status'] }}</span>
                                </td>

                                <td data-label="Actions">
                                    <div class="row-actions">
                                        @if ($row['type'] === 'personnel')
                                            <form method="POST" action="{{ route('superadmin.personnel-records.home-unit', $row['id']) }}" class="home-unit-form">
                                                @csrf
                                                @method('PATCH')
                                                <select name="home_unit_id">
                                                    <option value="">Not set</option>
                                                    @foreach ($units as $unit)
                                                        <option value="{{ $unit->id }}" {{ (int) ($row['home_unit']->id ?? 0) === $unit->id ? 'selected' : '' }}>
                                                            {{ $unit->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <button type="submit" class="home-unit-save">Save</button>
                                            </form>

                                            <button type="button" class="edit-link-btn js-pp-edit"
                                                data-id="{{ $row['id'] }}"
                                                data-first-name="{{ $row['model']->first_name }}"
                                                data-middle-name="{{ $row['model']->middle_name }}"
                                                data-last-name="{{ $row['model']->last_name }}"
                                                data-role="{{ $row['model']->role }}">
                                                Edit
                                            </button>

                                            <form method="POST" action="{{ route('superadmin.personnel-records.toggle', $row['id']) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="toggle-btn">
                                                    {{ $row['status'] === 'Active' ? 'Deactivate' : 'Activate' }}
                                                </button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('superadmin.personnel.home-unit', $row['id']) }}" class="home-unit-form">
                                                @csrf
                                                @method('PATCH')
                                                <select name="home_unit_id">
                                                    <option value="">Not set</option>
                                                    @foreach ($units as $unit)
                                                        <option value="{{ $unit->id }}" {{ (int) ($row['home_unit']->id ?? 0) === $unit->id ? 'selected' : '' }}>
                                                            {{ $unit->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <button type="submit" class="home-unit-save">Save</button>
                                            </form>

                                            <form method="POST" action="{{ route('superadmin.personnel.toggle', $row['id']) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="toggle-btn">
                                                    {{ $row['status'] === 'Active' ? 'Deactivate' : 'Activate' }}
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="empty-row">
                                        <span class="empty-row-title">No personnel records yet</span>
                                        <span class="empty-row-hint">Add a Driver or Pahinante, or check Team Leader accounts.</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="pp-modal" id="ppModal">
        <div class="pp-modal-card">
            <div class="pp-modal-header">
                <h2 id="ppModalTitle">Add Personnel</h2>
                <button type="button" class="pp-modal-close" data-close-pp-modal aria-label="Close">&times;</button>
            </div>

            <form method="POST" id="ppForm">
                @csrf
                <input type="hidden" name="_method" id="ppFormMethod" value="POST">

                <div class="pp-form-row">
                    <div class="pp-form-group">
                        <label for="ppFirstName">First Name</label>
                        <input type="text" name="first_name" id="ppFirstName" required>
                    </div>
                    <div class="pp-form-group">
                        <label for="ppLastName">Last Name</label>
                        <input type="text" name="last_name" id="ppLastName" required>
                    </div>
                </div>

                <div class="pp-form-group">
                    <label for="ppMiddleName">Middle Name (optional)</label>
                    <input type="text" name="middle_name" id="ppMiddleName">
                </div>

                <div class="pp-form-group">
                    <label for="ppRole">Role</label>
                    <select name="role" id="ppRole" required>
                        <option value="driver">Driver</option>
                        <option value="crew">Pahinante</option>
                    </select>
                </div>

                <div class="pp-form-group" id="ppHomeUnitGroup">
                    <label for="ppHomeUnit">Home Unit (optional)</label>
                    <select name="home_unit_id" id="ppHomeUnit">
                        <option value="">Not set</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="pp-modal-footer">
                    <button type="button" class="pp-btn-cancel" data-close-pp-modal>Cancel</button>
                    <button type="submit" class="pp-btn-save">Save</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const alertEl = document.getElementById('personnelSuccessAlert');
            if (alertEl) {
                setTimeout(() => {
                    alertEl.classList.add('fade-out');
                    setTimeout(() => alertEl.remove(), 300);
                }, 3500);
            }

            const baseUrl = document.querySelector('.personnel-page').dataset.baseUrl;
            const modal = document.getElementById('ppModal');
            const form = document.getElementById('ppForm');
            const methodField = document.getElementById('ppFormMethod');
            const homeUnitGroup = document.getElementById('ppHomeUnitGroup');
            const title = document.getElementById('ppModalTitle');

            function openModal() {
                modal.classList.add('is-open');
            }

            function closeModal() {
                modal.classList.remove('is-open');
                form.reset();
            }

            document.getElementById('ppAddBtn').addEventListener('click', function() {
                title.textContent = 'Add Personnel';
                form.action = baseUrl;
                methodField.value = 'POST';
                homeUnitGroup.style.display = '';
                openModal();
            });

            document.querySelectorAll('.js-pp-edit').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    title.textContent = 'Edit Personnel';
                    form.action = baseUrl + '/' + btn.dataset.id;
                    methodField.value = 'PUT';
                    homeUnitGroup.style.display = 'none';
                    document.getElementById('ppFirstName').value = btn.dataset.firstName || '';
                    document.getElementById('ppMiddleName').value = btn.dataset.middleName || '';
                    document.getElementById('ppLastName').value = btn.dataset.lastName || '';
                    document.getElementById('ppRole').value = btn.dataset.role || 'driver';
                    openModal();
                });
            });

            document.querySelectorAll('[data-close-pp-modal]').forEach(function(el) {
                el.addEventListener('click', closeModal);
            });
        });
    </script>
@endpush
