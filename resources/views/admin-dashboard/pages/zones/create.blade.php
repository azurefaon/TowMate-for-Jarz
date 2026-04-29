@extends('admin-dashboard.layouts.app')

@section('title', 'Add Zone')

@section('content')
    <style>
        .form-container {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }

        .form-card {
            background: white;
            /* border-radius: 16px; */
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.1);
            max-width: 600px;
            margin: 0 auto;
            overflow: hidden;
        }

        .form-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 32px;
            text-align: center;
        }

        .form-header h1 {
            margin: 0 0 8px 0;
            font-size: 1.75rem;
            font-weight: 800;
        }

        .form-header p {
            margin: 0;
            font-size: 0.95rem;
            opacity: 0.95;
        }

        .form-content {
            padding: 32px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group:last-child {
            margin-bottom: 0;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #0f172a;
            font-size: 0.95rem;
        }

        .form-input,
        .form-textarea,
        .form-select {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e5e7eb;
            /* border-radius: 8px; */
            font-size: 0.95rem;
            font-family: inherit;
            transition: all 0.2s ease;
            background: white;
        }

        .form-input:focus,
        .form-textarea:focus,
        .form-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: white;
        }

        .form-input::placeholder {
            color: #94a3b8;
        }

        .form-textarea {
            resize: vertical;
            min-height: 100px;
            line-height: 1.5;
        }

        .form-help {
            display: block;
            margin-top: 6px;
            font-size: 0.85rem;
            color: #64748b;
        }

        .team-leaders-section {
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            /* border-radius: 8px; */
            padding: 16px;
            margin-bottom: 20px;
        }

        .team-leaders-section h4 {
            margin: 0 0 12px 0;
            font-size: 0.95rem;
            color: #0f172a;
            font-weight: 600;
        }

        .team-leaders-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 200px;
            overflow-y: auto;
        }

        .team-leader-item {
            display: flex;
            align-items: center;
            padding: 10px 12px;
            background: white;
            border: 1px solid #e5e7eb;
            /* border-radius: 6px; */
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .team-leader-item:hover {
            background: #f0f4ff;
            border-color: #667eea;
        }

        .team-leader-checkbox {
            width: 18px;
            height: 18px;
            margin-right: 10px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #e5e7eb;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 24px;
            /* border-radius: 8px; */
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            flex: 1;
        }

        .btn-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.3);
        }

        .btn-cancel {
            background: #e5e7eb;
            color: #0f172a;
            border: 1px solid #d1d5db;
        }

        .btn-cancel:hover {
            background: #d1d5db;
        }

        .error-message {
            color: #dc2626;
            font-size: 0.85rem;
            margin-top: 6px;
            padding: 8px 12px;
            background: #fee2e2;
            /* border-radius: 6px; */
            border-left: 3px solid #dc2626;
        }

        .validation-error {
            border-color: #dc2626 !important;
        }

        @media (max-width: 640px) {
            .form-container {
                padding: 20px 10px;
            }

            .form-header {
                padding: 32px 20px;
            }

            .form-header h1 {
                font-size: 1.5rem;
            }

            .form-content {
                padding: 20px;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                flex: 1;
            }
        }
    </style>

    <div class="form-container">
        <div class="form-card">
            <div class="form-header">
                <h1>🗺️ Create New Zone</h1>
                <p>Add a new service zone to your system</p>
            </div>

            <div class="form-content">
                <form method="POST" action="{{ route('admin.zones.store') }}" id="zoneForm">
                    @csrf

                    <div class="form-group">
                        <label for="zoneName" class="form-label">Zone Name <span style="color: #dc2626;">*</span></label>
                        <input id="zoneName" type="text" name="name" value="{{ old('name') }}" required
                            class="form-input @error('name') validation-error @enderror"
                            placeholder="e.g., Downtown, North District, Harbor Area">
                        @error('name')
                            <div class="error-message">{{ $message }}</div>
                        @enderror
                        <span class="form-help">Choose a unique and descriptive name for this zone</span>
                    </div>

                    <div class="form-group">
                        <label for="zoneDesc" class="form-label">Description</label>
                        <textarea id="zoneDesc" name="description" class="form-textarea @error('description') validation-error @enderror"
                            placeholder="Describe the zone boundaries, coverage area, and any special notes...">{{ old('description') }}</textarea>
                        @error('description')
                            <div class="error-message">{{ $message }}</div>
                        @enderror
                        <span class="form-help">Provide details about the zone's coverage area and characteristics</span>
                    </div>

                    @if ($teamLeaders->count() > 0)
                        <div class="form-group">
                            <label class="form-label">🧑‍💼 Assign Team Leaders (Optional)</label>
                            <div class="team-leaders-section">
                                <h4>Available Team Leaders</h4>
                                <div class="team-leaders-list">
                                    @foreach ($teamLeaders as $leader)
                                        <label class="team-leader-item">
                                            <input type="checkbox" name="team_leader_ids[]" value="{{ $leader->id }}"
                                                class="team-leader-checkbox"
                                                {{ in_array($leader->id, old('team_leader_ids', [])) ? 'checked' : '' }}>
                                            <span>{{ $leader->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                            <span class="form-help">Select team leaders who will operate in this zone (can be changed
                                later)</span>
                        </div>
                    @else
                        <div
                            style="background: #fef3c7; border: 1px solid #fcd34d; padding: 12px 16px; margin-bottom: 20px;">
                            <p style="margin: 0; color: #92400e; font-size: 0.9rem;">
                                ℹ️ No team leaders available yet. You can assign team leaders to this zone after creation.
                            </p>
                        </div>
                    @endif

                    <div class="form-actions">
                        <a href="{{ route('admin.zones.index') }}" class="btn btn-cancel">Cancel</a>
                        <button type="submit" class="btn btn-submit">✓ Create Zone</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
