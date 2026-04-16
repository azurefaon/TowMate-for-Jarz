@extends('teamleader.layouts.app')

@section('title', 'Current Job')
@section('page_title', 'Current Job')

@php
    $teamLeaderAppUrl = rtrim(config('app.url') ?: request()->getSchemeAndHttpHost(), '/');
    $teamLeaderAssetBaseUrl = $teamLeaderAppUrl . '/teamleader-assets';
    $teamLeaderTasksCssPath = public_path('teamleader-assets/css/tasks.css');
@endphp

@push('styles')
    <link rel="stylesheet" type="text/css"
        href="{{ $teamLeaderAssetBaseUrl }}/css/tasks.css?v={{ filemtime($teamLeaderTasksCssPath) }}">

    @if (is_file($teamLeaderTasksCssPath))
        <style>
            {!! file_get_contents($teamLeaderTasksCssPath) !!}
        </style>
    @endif

    <style>
        .tl-dialog-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            z-index: 1200;
        }

        .tl-dialog-backdrop.hidden {
            display: none;
        }

        .tl-dialog-card {
            width: min(520px, 100%);
            background: #fff;
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.22);
        }

        .tl-dialog-card h3 {
            margin: 6px 0 8px;
        }

        .tl-dialog-card p {
            margin: 0 0 12px;
            color: #475569;
        }

        .tl-dialog-card select,
        .tl-dialog-card textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            padding: 10px 12px;
            font: inherit;
            margin-top: 8px;
        }

        .tl-dialog-card textarea {
            min-height: 110px;
            resize: vertical;
        }

        .tl-dialog-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 14px;
        }
    </style>
@endpush

@section('content')
    <div class="tl-focus-task" id="focusedTask" data-current-status="{{ $booking->status }}"
        data-driver-endpoint="{{ route('teamleader.task.driver', $booking) }}"
        data-note-endpoint="{{ route('teamleader.task.note', $booking) }}"
        data-proceed-endpoint="{{ route('teamleader.task.proceed', $booking) }}"
        data-start-endpoint="{{ route('teamleader.task.start', $booking) }}"
        data-complete-endpoint="{{ route('teamleader.task.complete', $booking) }}"
        data-return-endpoint="{{ route('teamleader.task.return', $booking) }}"
        data-status-endpoint="{{ route('teamleader.task.status', $booking) }}"
        data-dashboard-url="{{ route('teamleader.dashboard') }}" data-tasks-url="{{ route('teamleader.tasks') }}">

        <section class="tl-hero-card tl-focus-hero">
            <div class="tl-hero-card__content">
                <p class="tl-hero-card__eyebrow">Job {{ $booking->job_code }}</p>
                <h2>{{ $booking->pickup_address }} → {{ $booking->dropoff_address }}</h2>
                <p class="tl-hero-card__copy" id="focusStatusNote">{{ $task['status_note'] }}</p>
            </div>

            <div class="tl-hero-card__actions">
                <span class="tl-status-badge {{ str_replace('_', '-', $task['ui_status']) }}" id="focusStatusBadge">
                    {{ $task['status_label'] }}
                </span>
                <span class="tl-hero-card__hint">Assigned exclusively to your crew</span>
            </div>
        </section>

        <section class="tl-section-card tl-focus-panel">
            <div class="tl-section-card__header">
                <div>
                    <p class="tl-eyebrow">Focus Mode</p>
                    <h3>Stay on this booking until it is completed or returned</h3>
                </div>
            </div>

            <div class="tl-task-card__note">
                This booking is locked to your team. Only the next valid action is shown so the workflow stays clear and
                mistake-free.
            </div>

            <div class="tl-focus-stepper" id="focusStepper">
                <div class="tl-focus-step" data-step="claimed">
                    <span>1</span>
                    <strong>Claimed</strong>
                    <small>Reserved to your crew</small>
                </div>
                <div class="tl-focus-step" data-step="navigate">
                    <span>2</span>
                    <strong>Navigate</strong>
                    <small>Head to pickup</small>
                </div>
                <div class="tl-focus-step" data-step="work">
                    <span>3</span>
                    <strong>Start Job</strong>
                    <small>Begin towing</small>
                </div>
                <div class="tl-focus-step" data-step="verify">
                    <span>4</span>
                    <strong>Verify</strong>
                    <small>Send completion to customer</small>
                </div>
                <div class="tl-focus-step" data-step="done">
                    <span>5</span>
                    <strong>Complete</strong>
                    <small>Finish and unlock</small>
                </div>
            </div>
        </section>

        <div class="tl-focus-layout">
            <section class="tl-section-card tl-focus-panel">
                <div class="tl-section-card__header">
                    <div>
                        <p class="tl-eyebrow">Job Details</p>
                        <h3>Customer and dispatch information</h3>
                    </div>
                </div>

                <div class="tl-focus-details">
                    <div class="tl-focus-detail-card">
                        <small>Customer</small>
                        <strong>{{ $task['customer_name'] }}</strong>
                        <span>{{ $task['customer_phone'] }}</span>
                    </div>
                    <div class="tl-focus-detail-card">
                        <small>Assigned Truck</small>
                        <strong>{{ $task['unit_name'] }}</strong>
                        <span>{{ $task['unit_plate'] }} · {{ $task['truck_type'] }}</span>
                    </div>
                    <div class="tl-focus-detail-card">
                        <small>Quotation</small>
                        <strong>{{ $task['quotation_number'] }}</strong>
                        <span>Last update {{ $task['updated_at_human'] }}</span>
                    </div>
                </div>

                <div class="tl-task-card__note">
                    <strong>Pickup:</strong> {{ $task['pickup_address'] }}<br>
                    <strong>Drop-off:</strong> {{ $task['dropoff_address'] }}
                </div>
            </section>

            <section class="tl-section-card tl-focus-panel">
                <div class="tl-section-card__header">
                    <div>
                        <p class="tl-eyebrow">Job Update</p>
                        <h3>Keep this service moving step by step</h3>
                    </div>
                </div>

                <div class="tl-focus-driver-block">
                    <label for="driverNameInput">Driver Name</label>
                    <div class="tl-focus-driver-row">
                        <input type="text" id="driverNameInput" value="{{ $task['driver_name'] ?? '' }}"
                            placeholder="{{ $task['driver_locked'] ? 'Click Change Driver to update the saved driver' : 'Enter driver name' }}"
                            maxlength="120" @disabled($task['driver_locked'])>
                        <button type="button" class="tl-btn tl-btn--ghost" id="saveDriverBtn" @disabled($task['driver_locked'])>
                            {{ $task['driver_locked'] ? 'Driver Saved' : (filled($task['driver_name'] ?? null) ? 'Update Driver' : 'Save Driver') }}
                        </button>
                        <button type="button" class="tl-btn tl-btn--ghost {{ $task['can_edit_driver'] ? '' : 'hidden' }}"
                            id="changeDriverBtn">
                            Change Driver
                        </button>
                    </div>
                    <small class="tl-input-hint">Driver name and progress notes save automatically while you work.</small>
                </div>

                <div class="tl-focus-driver-block">
                    <label for="completionNoteInput">Completion Note</label>
                    <textarea id="completionNoteInput" rows="3"
                        placeholder="{{ $task['completion_note_locked'] ? 'Completion note becomes available during the final step' : 'Add a short note before sending customer verification...' }}"
                        @disabled($task['completion_note_locked'])>{{ $task['completion_note'] ?? '' }}</textarea>
                </div>

                <div class="tl-focus-action-stack" id="focusActionGroup">
                    <button type="button"
                        class="tl-btn tl-btn--primary tl-btn--full {{ $task['can_proceed'] ? '' : 'hidden' }}"
                        id="proceedBtn">
                        Navigate to Pickup
                    </button>
                    <button type="button"
                        class="tl-btn tl-btn--primary tl-btn--full {{ $task['can_start'] ? '' : 'hidden' }}"
                        id="startTowBtn">
                        Arrived - Start Job
                    </button>
                    <button type="button"
                        class="tl-btn tl-btn--primary tl-btn--full {{ $task['can_complete'] ? '' : 'hidden' }}"
                        id="completeTaskBtn">
                        Complete Job
                    </button>
                    <button type="button"
                        class="tl-btn tl-btn--ghost tl-btn--full {{ $task['can_return'] ? '' : 'hidden' }}"
                        id="returnTaskBtn">
                        Return to Dispatch
                    </button>
                    <a href="{{ route('teamleader.dashboard') }}" class="tl-btn tl-btn--success tl-btn--full hidden"
                        id="backToDashboardBtn">
                        Back to Dashboard
                    </a>
                </div>

                <p class="tl-focus-feedback" id="focusFeedback">Use the buttons below to keep this job updated.</p>
                <small class="tl-input-hint">Jarz keeps this task synced with dispatch in real time.</small>
            </section>
        </div>
    </div>

    <div class="tl-dialog-backdrop hidden" id="returnTaskModal" aria-hidden="true">
        <div class="tl-dialog-card">
            <p class="tl-eyebrow">Return to Dispatch</p>
            <h3>Why are you returning this booking?</h3>
            <p>Dispatch will be notified right away so the task can be reassigned quickly.</p>

            <label for="returnReasonPreset">Reason</label>
            <select id="returnReasonPreset">
                <option value="">Select a reason</option>
                <option value="Wrong assignment">Wrong assignment</option>
                <option value="Vehicle issue">Vehicle issue</option>
                <option value="Customer unreachable">Customer unreachable</option>
                <option value="Unsafe location">Unsafe location</option>
                <option value="Emergency situation">Emergency situation</option>
                <option value="Other">Other</option>
            </select>

            <label for="returnReasonNote" style="display:block; margin-top:12px;">Notes for dispatch</label>
            <textarea id="returnReasonNote" placeholder="Add a short explanation so dispatch can act faster..."></textarea>

            <p class="tl-focus-feedback is-error hidden" id="returnReasonError"></p>

            <div class="tl-dialog-actions">
                <button type="button" class="tl-btn tl-btn--ghost" id="cancelReturnBtn">Cancel</button>
                <button type="button" class="tl-btn tl-btn--primary" id="confirmReturnBtn">Confirm Return</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ $teamLeaderAssetBaseUrl }}/js/task-focus.js?v={{ time() }}"></script>
@endpush
