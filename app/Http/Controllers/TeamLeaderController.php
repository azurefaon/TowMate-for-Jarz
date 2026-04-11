<?php

namespace App\Http\Controllers;

use App\Events\BookingStatusUpdated;
use App\Mail\BookingReceiptMail;
use App\Mail\TaskCompletionVerificationMail;
use App\Models\Booking;
use App\Services\DocumentGenerationService;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class TeamLeaderController extends Controller
{
    public function __construct(protected TeamLeaderAvailabilityService $teamLeaderAvailability) {}

    protected array $activeStatuses = ['assigned', 'on_the_way', 'in_progress', 'waiting_verification'];

    protected function touchPresence(): void
    {
        if ((int) optional(Auth::user())->role_id === 3) {
            $this->teamLeaderAvailability->markOnline(Auth::user());
        }
    }

    public function index()
    {
        return $this->dashboard(request());
    }

    public function dashboard(Request $request)
    {
        $this->touchPresence();

        if ($activeTask = $this->activeTask()) {
            return redirect()->route('teamleader.task.show', $activeTask);
        }

        $stats = $this->buildStats($this->overviewBookingsQuery());
        $recentTasks = $this->overviewBookingsQuery()
            ->latest('updated_at')
            ->take(6)
            ->get();

        return view('teamleader.dashboard', compact('stats', 'recentTasks'));
    }

    public function tasks(Request $request)
    {
        $this->touchPresence();

        if ($activeTask = $this->activeTask()) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Finish or return your active task before opening the queue again.',
                    'active_task_id' => $activeTask->job_code,
                    'redirect_url' => route('teamleader.task.show', $activeTask),
                ], 409);
            }

            return redirect()->route('teamleader.task.show', $activeTask);
        }

        $stats = $this->buildStats($this->overviewBookingsQuery());
        $bookings = $this->queueBookingsQuery()
            ->latest('updated_at')
            ->get();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'stats' => $stats,
                'tasks' => $bookings->map(fn(Booking $booking) => $this->transformBooking($booking))->values(),
            ]);
        }

        return view('teamleader.tasks', compact('bookings', 'stats'));
    }

    public function showTask(Booking $booking)
    {
        $this->touchPresence();

        $task = $this->resolveOwnedTask($booking, true);

        return view('teamleader.task-focus', [
            'booking' => $task,
            'task' => $this->transformBooking($task),
        ]);
    }

    public function heartbeat(Request $request)
    {
        $this->touchPresence();

        return response()->json([
            'success' => true,
            'presence' => 'online',
        ]);
    }

    public function goOffline(Request $request)
    {
        $this->teamLeaderAvailability->markOffline($request->user());

        return response()->json([
            'success' => true,
            'presence' => 'offline',
        ]);
    }

    public function acceptTask(Request $request, Booking $booking)
    {
        $teamLeaderId = Auth::id();
        $activeTask = $this->activeTask();

        if ($activeTask && $activeTask->id !== $booking->id) {
            return response()->json([
                'success' => false,
                'message' => 'You already have an active task. Finish or return it first.',
                'active_task_id' => $activeTask->job_code,
                'redirect_url' => route('teamleader.task.show', $activeTask),
            ], 422);
        }

        $task = $this->queueBookingsQuery()->findOrFail($booking->id);

        if ((int) $task->assigned_team_leader_id === (int) $teamLeaderId) {
            return response()->json([
                'success' => true,
                'message' => 'Task is already assigned to you.',
                'status' => $task->status,
                'task' => $this->transformBooking($task),
                'redirect_url' => route('teamleader.task.show', $task),
            ]);
        }

        $assignedUnitId = $task->assigned_unit_id ?: optional(Auth::user()->unit)->id;

        $updated = Booking::query()
            ->whereKey($task->id)
            ->whereNull('assigned_team_leader_id')
            ->whereIn('status', ['confirmed', 'accepted', 'assigned'])
            ->update([
                'assigned_team_leader_id' => $teamLeaderId,
                'assigned_unit_id' => $assignedUnitId,
                'status' => 'assigned',
                'assigned_at' => now(),
                'customer_verification_status' => null,
                'customer_verified_at' => null,
                'completion_requested_at' => null,
                'customer_verification_note' => null,
            ]);

        if (! $updated) {
            return response()->json([
                'success' => false,
                'message' => 'This task was already accepted by another team leader.',
            ], 409);
        }

        $task = $this->resolveOwnedTask($booking->fresh(), true);
        event(new BookingStatusUpdated($task));

        return response()->json([
            'success' => true,
            'message' => 'Task accepted. Focus mode is now active.',
            'status' => $task->status,
            'task' => $this->transformBooking($task),
            'redirect_url' => route('teamleader.task.show', $task),
        ]);
    }

    public function saveDriver(Request $request, Booking $booking)
    {
        $task = $this->resolveOwnedTask($booking, true);

        if ($task->status !== 'assigned') {
            return response()->json([
                'success' => false,
                'message' => 'Driver details can only be changed before this job leaves the assigned stage.',
            ], 422);
        }

        $validated = $request->validate([
            'driver_name' => 'required|string|max:120',
        ]);

        $hadDriver = filled($task->driver_name);

        $task->update([
            'driver_name' => trim(strip_tags($validated['driver_name'])),
        ]);

        $task->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
        event(new BookingStatusUpdated($task));

        return response()->json([
            'success' => true,
            'message' => $hadDriver ? 'Driver updated.' : 'Driver name saved.',
            'task' => $this->transformBooking($task),
        ]);
    }

    public function autosaveNote(Request $request, Booking $booking)
    {
        $task = $this->resolveOwnedTask($booking, true);

        if (! in_array($task->status, ['assigned', 'on_the_way', 'in_progress', 'waiting_verification'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Notes are not editable for this task right now.',
            ], 422);
        }

        $validated = $request->validate([
            'completion_note' => 'nullable|string|max:1000',
        ]);

        $task->update([
            'customer_verification_note' => filled($validated['completion_note'] ?? null)
                ? trim(strip_tags($validated['completion_note']))
                : null,
        ]);

        $task->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
        event(new BookingStatusUpdated($task));

        return response()->json([
            'success' => true,
            'message' => 'Progress notes saved automatically.',
            'task' => $this->transformBooking($task),
        ]);
    }

    public function proceedToLocation(Request $request, Booking $booking)
    {
        $task = $this->resolveOwnedTask($booking, true);

        if ($task->status !== 'assigned') {
            return response()->json([
                'success' => false,
                'message' => 'This task cannot move to travel mode right now.',
            ], 422);
        }

        if (! filled($task->driver_name)) {
            return response()->json([
                'success' => false,
                'message' => 'Enter the driver name before proceeding to the location.',
            ], 422);
        }

        $task->update([
            'status' => 'on_the_way',
        ]);

        $task->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
        event(new BookingStatusUpdated($task));

        return response()->json([
            'success' => true,
            'message' => 'Crew is now on the way to the customer location.',
            'status' => $task->status,
            'task' => $this->transformBooking($task),
        ]);
    }

    public function startTask(Request $request, Booking $booking)
    {
        $task = $this->resolveOwnedTask($booking, true);

        if ($task->status !== 'on_the_way') {
            return response()->json([
                'success' => false,
                'message' => 'Proceed to the location first before starting towing.',
            ], 422);
        }

        $task->update([
            'status' => 'in_progress',
            'completion_requested_at' => null,
            'customer_verified_at' => null,
            'customer_verification_status' => null,
        ]);

        $task->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
        event(new BookingStatusUpdated($task));

        return response()->json([
            'success' => true,
            'message' => 'Towing has started.',
            'status' => $task->status,
            'task' => $this->transformBooking($task),
        ]);
    }

    public function completeTask(Request $request, Booking $booking)
    {
        $task = $this->resolveOwnedTask($booking, true);

        $validated = $request->validate([
            'completion_note' => 'nullable|string|max:1000',
        ]);

        if ($task->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Only an active towing task can be marked for verification.',
            ], 422);
        }

        if (! filled($task->customer?->email)) {
            return response()->json([
                'success' => false,
                'message' => 'Customer email is missing. Verification cannot be sent yet.',
            ], 422);
        }

        $approveUrl = URL::temporarySignedRoute(
            'teamleader.verification.respond',
            now()->addHours(24),
            ['booking' => $task, 'decision' => 'approve']
        );

        $task->update([
            'status' => 'waiting_verification',
            'completion_requested_at' => now(),
            'customer_verification_status' => 'pending',
            'customer_verification_note' => filled($validated['completion_note'] ?? null)
                ? trim(strip_tags($validated['completion_note']))
                : null,
            'completed_at' => null,
        ]);

        $task->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
        event(new BookingStatusUpdated($task));

        Mail::to($task->customer->email)->send(
            new TaskCompletionVerificationMail($task, $approveUrl)
        );

        return response()->json([
            'success' => true,
            'message' => 'Customer verification was sent successfully.',
            'status' => $task->status,
            'task' => $this->transformBooking($task),
        ]);
    }

    public function returnTask(Request $request, Booking $booking)
    {
        $task = $this->resolveOwnedTask($booking, true);

        if (! in_array($task->status, ['assigned', 'on_the_way', 'in_progress'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This task can no longer be returned from its current state.',
            ], 422);
        }

        $task->update([
            'assigned_team_leader_id' => null,
            'driver_name' => null,
            'status' => 'assigned',
            'completion_requested_at' => null,
            'customer_verified_at' => null,
            'customer_verification_status' => null,
            'customer_verification_note' => null,
        ]);

        $task->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
        event(new BookingStatusUpdated($task));

        return response()->json([
            'success' => true,
            'message' => 'Task returned to the queue.',
            'status' => 'assigned',
            'redirect_url' => route('teamleader.tasks'),
        ]);
    }

    public function confirmCompletion(Request $request, Booking $booking)
    {
        return $this->completeTask($request, $booking);
    }

    public function taskStatus(Booking $booking)
    {
        $task = $this->resolveOwnedTask($booking, true);

        return response()->json([
            'success' => true,
            'task' => $this->transformBooking($task),
            'redirect_url' => $task->status === 'completed' ? route('teamleader.dashboard') : route('teamleader.task.show', $task),
        ]);
    }

    public function respondToVerification(Request $request, Booking $booking, string $decision)
    {
        abort_unless($request->hasValidSignature(), 403);
        abort_unless(in_array($decision, ['approve', 'reject'], true), 404);

        $booking->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);

        if ($decision === 'approve') {
            $booking->update([
                'status' => 'completed',
                'customer_verification_status' => 'approved',
                'customer_verified_at' => now(),
                'completed_at' => now(),
            ]);

            $booking->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader', 'receipt']);
            event(new BookingStatusUpdated($booking));

            $receipt = app(DocumentGenerationService::class)->generateReceipt($booking);

            if (filled($booking->customer?->email)) {
                Mail::to($booking->customer->email)->send(new BookingReceiptMail($booking->fresh(['customer', 'truckType', 'receipt'])));
                $receipt->update(['email_sent' => true]);
            }

            return response($this->verificationResponseHtml(
                'Task completion confirmed',
                'Thank you. Your response has been recorded and the towing job is now marked as completed.'
            ))->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $booking->update([
            'status' => 'in_progress',
            'customer_verification_status' => 'rejected',
            'customer_verified_at' => null,
        ]);

        event(new BookingStatusUpdated($booking->fresh(['customer', 'truckType', 'unit', 'assignedTeamLeader'])));

        return response($this->verificationResponseHtml(
            'Verification requires retry',
            'We received your response. The team leader can continue the task and resend completion later.'
        ))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    protected function activeTask(): ?Booking
    {
        return $this->ownedTasksQuery()->first();
    }

    protected function overviewBookingsQuery(): Builder
    {
        $teamLeaderId = Auth::id();

        return Booking::with(['customer', 'truckType', 'unit', 'assignedTeamLeader'])
            ->whereIn('status', ['confirmed', 'accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'completed'])
            ->where(function (Builder $query) use ($teamLeaderId) {
                $query->where('assigned_team_leader_id', $teamLeaderId)
                    ->orWhere(function (Builder $subQuery) use ($teamLeaderId) {
                        $subQuery->whereNull('assigned_team_leader_id')
                            ->where(function (Builder $visibilityQuery) use ($teamLeaderId) {
                                $visibilityQuery->whereNull('assigned_unit_id')
                                    ->orWhereHas('unit', function (Builder $unitQuery) use ($teamLeaderId) {
                                        $unitQuery->where('team_leader_id', $teamLeaderId);
                                    });
                            });
                    });
            });
    }

    protected function queueBookingsQuery(): Builder
    {
        $teamLeaderId = Auth::id();

        return Booking::with(['customer', 'truckType', 'unit', 'assignedTeamLeader'])
            ->whereIn('status', ['confirmed', 'accepted', 'assigned'])
            ->whereNull('assigned_team_leader_id')
            ->where(function (Builder $query) use ($teamLeaderId) {
                $query->whereNull('assigned_unit_id')
                    ->orWhereHas('unit', function (Builder $unitQuery) use ($teamLeaderId) {
                        $unitQuery->where('team_leader_id', $teamLeaderId);
                    });
            });
    }

    protected function ownedTasksQuery(bool $includeCompleted = false): Builder
    {
        $statuses = $includeCompleted
            ? array_values(array_unique([...$this->activeStatuses, 'completed']))
            : $this->activeStatuses;

        return Booking::with(['customer', 'truckType', 'unit', 'assignedTeamLeader'])
            ->where('assigned_team_leader_id', Auth::id())
            ->whereIn('status', $statuses)
            ->latest('updated_at');
    }

    protected function resolveOwnedTask(Booking $booking, bool $includeCompleted = false): Booking
    {
        return $this->ownedTasksQuery($includeCompleted)->findOrFail($booking->id);
    }

    protected function buildStats(Builder $query): array
    {
        $bookings = $query->get();

        return [
            'assigned' => $bookings->whereIn('status', ['confirmed', 'accepted', 'assigned'])->count(),
            'in_progress' => $bookings->whereIn('status', ['on_the_way', 'in_progress'])->count(),
            'waiting_verification' => $bookings->where('status', 'waiting_verification')->count(),
            'completed_today' => $bookings->filter(fn(Booking $booking) => $booking->status === 'completed' && optional($booking->completed_at)->isToday())->count(),
            'total' => $bookings->count(),
        ];
    }

    protected function transformBooking(Booking $booking): array
    {
        $uiStatus = match ($booking->status) {
            'confirmed', 'accepted', 'assigned' => 'assigned',
            'on_the_way' => 'on_the_way',
            'in_progress' => 'in_progress',
            'waiting_verification' => 'waiting_verification',
            'completed' => 'completed',
            default => $booking->status,
        };

        return [
            'id' => $booking->booking_code ?: $booking->id,
            'booking_code' => $booking->job_code,
            'status' => $booking->status,
            'ui_status' => $uiStatus,
            'status_label' => match ($uiStatus) {
                'assigned' => 'Ready',
                'on_the_way' => 'On the Way',
                'in_progress' => 'Towing in Progress',
                'waiting_verification' => 'Awaiting Customer Confirmation',
                'completed' => 'Completed',
                default => ucfirst(str_replace('_', ' ', $booking->status)),
            },
            'status_note' => match ($uiStatus) {
                'assigned' => filled($booking->driver_name)
                    ? 'Driver details are saved. You can still change the driver before leaving.'
                    : 'Add the driver name so this job is ready to leave.',
                'on_the_way' => 'Your crew is heading to the pickup location now.',
                'in_progress' => 'The tow is underway. Add the final note once everything is done.',
                'waiting_verification' => 'A confirmation request has been sent to the customer.',
                'completed' => 'The customer confirmed the service. This job is finished.',
                default => 'Job updated.',
            },
            'customer_name' => $booking->customer->full_name ?? 'Guest',
            'customer_phone' => $booking->customer->phone ?? 'N/A',
            'pickup_address' => $booking->pickup_address ?? 'Unknown pickup',
            'dropoff_address' => $booking->dropoff_address ?? 'Unknown drop-off',
            'truck_type' => $booking->truckType->name ?? 'General Towing',
            'unit_name' => $booking->unit->name ?? 'Dispatch-assigned unit',
            'unit_plate' => $booking->unit->plate_number ?? 'Plate pending',
            'quotation_number' => $booking->quotation_number ?? 'Pending',
            'driver_name' => $booking->driver_name,
            'updated_at_human' => optional($booking->updated_at)->diffForHumans() ?? 'Just now',
            'completion_note' => $booking->customer_verification_note,
            'assigned_to_me' => (int) $booking->assigned_team_leader_id === (int) Auth::id(),
            'can_accept' => in_array($booking->status, ['confirmed', 'accepted', 'assigned'], true) && empty($booking->assigned_team_leader_id),
            'can_proceed' => $booking->status === 'assigned',
            'can_start' => $booking->status === 'on_the_way',
            'can_complete' => $booking->status === 'in_progress',
            'can_return' => in_array($booking->status, ['assigned', 'on_the_way', 'in_progress'], true),
            'driver_locked' => $booking->status === 'assigned' && filled($booking->driver_name),
            'can_edit_driver' => $booking->status === 'assigned' && filled($booking->driver_name),
            'completion_note_locked' => $booking->status !== 'in_progress',
            'is_waiting' => $booking->status === 'waiting_verification',
            'is_completed' => $booking->status === 'completed',
            'task_url' => route('teamleader.task.show', $booking),
            'accept_url' => route('teamleader.task.accept', $booking),
        ];
    }

    protected function verificationResponseHtml(string $title, string $message): string
    {
        $safeTitle = e($title);
        $safeMessage = e($message);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$safeTitle}</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f7f7f7; color: #111111; margin: 0; padding: 24px; }
        .card { max-width: 520px; margin: 40px auto; background: #ffffff; border-radius: 16px; padding: 32px; box-shadow: 0 10px 30px rgba(17,17,17,0.08); border: 1px solid #ececec; }
        h1 { margin-top: 0; font-size: 24px; }
        p { line-height: 1.6; color: #4b5563; }
        .badge { display: inline-block; margin-bottom: 16px; padding: 8px 12px; border-radius: 999px; background: #fff7e0; color: #9a6700; font-weight: 700; }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge">Jarz Verification</span>
        <h1>{$safeTitle}</h1>
        <p>{$safeMessage}</p>
        <p>You may safely close this page.</p>
    </div>
</body>
</html>
HTML;
    }
}
