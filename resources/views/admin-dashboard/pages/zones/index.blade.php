@extends('admin-dashboard.layouts.app')

@section('title', 'Zones Management')

@section('content')
    <style>
        .zones-hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 48px 24px;
            border-radius: 16px;
            margin-bottom: 40px;
            box-shadow: 0 20px 60px rgba(102, 126, 234, 0.2);
        }

        .zones-hero h1 {
            margin: 0 0 12px 0;
            font-size: 2.5rem;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .zones-hero p {
            margin: 0;
            font-size: 1.1rem;
            opacity: 0.95;
        }

        .zones-container {
            display: grid;
            gap: 20px;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        }

        .zone-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .zone-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }

        .zone-card:hover {
            box-shadow: 0 12px 24px rgba(102, 126, 234, 0.15);
            transform: translateY(-4px);
            border-color: #667eea;
        }

        .zone-card__header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 12px;
        }

        .zone-card__title {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
        }

        .zone-card__badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f0f4ff;
            color: #667eea;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .zone-card__description {
            color: #64748b;
            font-size: 0.95rem;
            line-height: 1.5;
            margin-bottom: 16px;
            min-height: 40px;
        }

        .zone-card__actions {
            display: flex;
            gap: 8px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #e5e7eb;
        }

        .btn-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.3);
        }

        .btn-edit {
            background: #f0f4ff;
            color: #667eea;
            flex: 1;
            border: 1px solid #dbeafe;
        }

        .btn-edit:hover {
            background: #e0e9ff;
            border-color: #667eea;
        }

        .btn-delete {
            background: #fee2e2;
            color: #dc2626;
            flex: 1;
            border: 1px solid #fecaca;
        }

        .btn-delete:hover {
            background: #fecaca;
            border-color: #dc2626;
        }

        .empty-state {
            grid-column: 1 / -1;
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
            margin: 0 0 24px 0;
            color: #64748b;
        }

        .btn-add-new {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 28px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            display: inline-block;
            transition: all 0.2s ease;
        }

        .btn-add-new:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.3);
        }

        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            border-left: 4px solid;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border-color: #22c55e;
        }

        @media (max-width: 768px) {
            .zones-hero {
                padding: 32px 16px;
            }

            .zones-hero h1 {
                font-size: 1.75rem;
            }

            .zones-container {
                grid-template-columns: 1fr;
            }

            .zone-card__actions {
                flex-direction: column;
            }

            .btn-edit,
            .btn-delete {
                flex: 1;
            }
        }
    </style>

    <div class="zones-page">
        <div class="zones-hero">
            <h1>🗺️ Zones Management</h1>
            <p>Manage all zones and their coverage areas</p>
        </div>

        @if (session('success'))
            <div class="alert alert-success">
                <strong>✓ Success!</strong> {{ session('success') }}
            </div>
        @endif

        <div style="margin-bottom: 32px;">
            <a href="{{ route('admin.zones.create') }}" class="btn-add-new">+ Add New Zone</a>
        </div>

        @forelse ($zones as $zone)
            <div class="zones-container">
                <div class="zone-card">
                    <div class="zone-card__header">
                        <h3 class="zone-card__title">{{ $zone->name }}</h3>
                        <span class="zone-card__badge">
                            <span style="font-size: 1rem;">📍</span>
                            Active
                        </span>
                    </div>
                    <p class="zone-card__description">
                        {{ $zone->description ?? 'No description provided' }}
                    </p>
                    <div class="zone-card__actions">
                        <a href="{{ route('admin.zones.edit', $zone) }}" class="btn btn-edit">
                            ✏️ Edit
                        </a>
                        <form action="{{ route('admin.zones.destroy', $zone) }}" method="POST" style="flex: 1;">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-delete" style="width: 100%;"
                                onclick="return confirm('Are you sure you want to delete this zone? This action cannot be undone.')">
                                🗑️ Delete
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <div class="zones-container">
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <h3>No Zones Found</h3>
                    <p>You haven't created any zones yet. Get started by adding your first zone!</p>
                    <a href="{{ route('admin.zones.create') }}" class="btn-add-new">Create First Zone</a>
                </div>
            </div>
        @endforelse
    </div>
@endsection
