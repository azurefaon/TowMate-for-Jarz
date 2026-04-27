<?php

namespace App\Http\Controllers;

use App\Enums\ReturnReason;
use App\Events\BookingStatusUpdated;
use App\Mail\BookingReceiptMail;
use App\Mail\TaskCompletionVerificationMail;
use App\Models\Booking;
use App\Models\Unit;
use App\Services\DocumentGenerationService;
use App\Services\TeamLeaderAvailabilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

class TeamLeaderController extends Controller
{
    public function __construct(protected TeamLeaderAvailabilityService $teamLeaderAvailability) {}

    protected array $activeStatuses = ['assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'payment_pending', 'payment_submitted'];

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

        $stats = $this->buildStats($this->overviewBookingsQuery());
        $activeTask = $this->activeTask();

        $bookings = $activeTask
            ? collect([$activeTask])
            : $this->queueBookingsQuery()->latest('updated_at')->get();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'focus_locked' => filled($activeTask),
                'active_task_id' => $activeTask?->job_code,
                'redirect_url' => $activeTask ? route('teamleader.task.show', $activeTask) : null,
                'stats' => $stats,
                'tasks' => $bookings->map(fn(Booking $booking) => $this->transformBooking($booking))->values(),
            ]);
        }

        $focusLocked = filled($activeTask);

        return view('teamleader.tasks', compact('bookings', 'stats', 'focusLocked'));
    }

    public function showTask(Booking $booking)
    {
        $this->touchPresence();

        if ($activeTask = $this->activeTask()) {
            if ((int) $activeTask->id !== (int) $booking->id) {
                if (request()->expectsJson() || request()->ajax()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Focus mode is active on another job.',
                        'redirect_url' => route('teamleader.task.show', $activeTask),
                    ], 409);
                }

                return redirect()->route('teamleader.task.show', $activeTask);
            }
        }

        $task = $this->ownedTasksQuery(true)->find($booking->id);

        if (! $task) {
            if (request()->expectsJson() || request()->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Claim a booking from the task list first.',
                    'redirect_url' => route('teamleader.tasks'),
                ], 409);
            }

            return redirect()->route('teamleader.tasks')
                ->with('info', 'Claim a booking from the task list first.');
        }

        return view('teamleader.task-focus', [
            'booking'           => $task,
            'task'              => $this->transformBooking($task),
            'gcashNumber'       => \App\Models\SystemSetting::getValue('gcash_number', '12345678901'),
            'bankName'          => \App\Models\SystemSetting::getValue('bank_name', 'BDO Unibank'),
            'bankAccountName'   => \App\Models\SystemSetting::getValue('bank_account_name', 'Jarz Towing Services'),
            'bankAccountNumber' => \App\Models\SystemSetting::getValue('bank_account_number', '1234-5678-9101'),
            'allowCheque'       => (bool) \App\Models\SystemSetting::getValue('allow_cheque_payment', false),
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
        $this->touchPresence();

        $teamLeaderId = Auth::id();

        $unit = Auth::user()->unit;
        if (! $unit) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have a registered unit. Contact your admin to register a unit before accepting tasks.',
            ], 422);
        }

        if ($unit->dispatcher_status === 'unavailable') {
            return response()->json([
                'success' => false,
                'message' => 'Your unit has been marked Not Available by the dispatcher. Contact them before accepting tasks.',
            ], 422);
        }

        $activeTask = $this->activeTask();

        if ($activeTask && $activeTask->id !== $booking->id) {
            return response()->json([
                'success' => false,
                'message' => 'You already have an active task. Finish or return it first.',
                'active_task_id' => $activeTask->job_code,
                'redirect_url' => route('teamleader.task.show', $activeTask),
            ], 422);
        }

        // Try to get from the approved queue first, if not found check if already assigned to you
        $task = $this->queueBookingsQuery()->find($booking->id);

        if (!$task) {
            // Check if it's already assigned to you (any status)
            $task = Booking::with(['customer', 'truckType', 'unit', 'assignedTeamLeader'])
                ->where('id', $booking->id)
                ->where('assigned_team_leader_id', $teamLeaderId)
                ->whereIn('status', ['assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'completed'])
                ->first();

            if ($task) {
                return response()->json([
                    'success' => true,
                    'message' => 'Task is already in your queue.',
                    'status' => $task->status,
                    'task' => $this->transformBooking($task),
                    'redirect_url' => route('teamleader.task.show', $task),
                ]);
            }

            abort(404, 'This task is not available for you to accept.');
        }

        $assignedUnitId = $task->assigned_unit_id ?: optional(Auth::user()->unit)->id;

        // Allow accepting a booking that is unclaimed OR pre-assigned to this TL (e.g., dispatcher
        // already linked the TL when sending the quotation — TL still needs to formally accept).
        $updated = Booking::query()
            ->whereKey($task->id)
            ->where(function (Builder $q) use ($teamLeaderId) {
                $q->whereNull('assigned_team_leader_id')
                    ->orWhere('assigned_team_leader_id', $teamLeaderId);
            })
            ->whereIn('status', ['quotation_sent', 'confirmed', 'accepted', 'assigned'])
            ->update([
                'assigned_team_leader_id' => $teamLeaderId,
                'assigned_unit_id' => $assignedUnitId,
                'status' => 'assigned',
                'assigned_at' => now(),
                'customer_verification_status' => null,
                'customer_verified_at' => null,
                'completion_requested_at' => null,
                'customer_verification_note' => null,
                'returned_at' => null,
                'return_reason' => null,
                'returned_by_team_leader_id' => null,
            ]);

        if (! $updated) {
            // Already past the accept point for this TL (on_the_way, in_progress, etc.)
            $alreadyActive = Booking::with(['customer', 'truckType', 'unit', 'assignedTeamLeader'])
                ->whereKey($task->id)
                ->where('assigned_team_leader_id', $teamLeaderId)
                ->whereIn('status', $this->activeStatuses)
                ->first();

            if ($alreadyActive) {
                return response()->json([
                    'success' => true,
                    'message' => 'Task is already in progress.',
                    'status' => $alreadyActive->status,
                    'task' => $this->transformBooking($alreadyActive),
                    'redirect_url' => route('teamleader.task.show', $alreadyActive),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'This task was already accepted by another team leader.',
            ], 409);
        }

        $task = $this->resolveOwnedTask($booking->fresh(), true);
        $this->syncAssignedUnitStatus($task, 'on_job');
        event(new BookingStatusUpdated($task));

        return response()->json([
            'success' => true,
            'message' => 'Task claimed successfully. Focus mode is now active.',
            'status' => $task->status,
            'task' => $this->transformBooking($task),
            'redirect_url' => route('teamleader.task.show', $task),
        ]);
    }

    public function saveDriver(Request $request, Booking $booking)
    {
        $this->touchPresence();

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
        $this->touchPresence();

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
        $this->touchPresence();

        $task = $this->resolveOwnedTask($booking, true);

        if ($task->status !== 'assigned') {
            return response()->json([
                'success' => false,
                'message' => 'This task cannot move to travel mode right now.',
            ], 422);
        }

        $task->update([
            'status' => 'on_the_way',
        ]);

        $task->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
        $this->syncAssignedUnitStatus($task, 'on_job');
        event(new BookingStatusUpdated($task));

        return response()->json([
            'success' => true,
            'message' => 'Navigation started. Head to the pickup location now.',
            'status' => $task->status,
            'task' => $this->transformBooking($task),
        ]);
    }

    public function startTask(Request $request, Booking $booking)
    {
        $this->touchPresence();

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
        $this->syncAssignedUnitStatus($task, 'on_job');
        event(new BookingStatusUpdated($task));

        return response()->json([
            'success' => true,
            'message' => 'Arrival confirmed. The job is now in progress.',
            'status' => $task->status,
            'task' => $this->transformBooking($task),
        ]);
    }

    public function completeTask(Request $request, Booking $booking)
    {
        $this->touchPresence();

        $task = $this->resolveOwnedTask($booking, true);

        if ($task->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Only an active towing task can request payment.',
            ], 422);
        }

        $task->update([
            'status'                  => 'payment_pending',
            'completion_requested_at' => now(),
            'completed_at'            => null,
            'paymongo_intent_id'      => null,
            'paymongo_client_key'     => null,
            'paymongo_link_id'        => null,
            'paymongo_checkout_url'   => null,
        ]);

        $task->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
        $this->syncAssignedUnitStatus($task, 'available');
        $this->teamLeaderAvailability->setOperationalOverride(Auth::user(), 'available');
        event(new BookingStatusUpdated($task));

        return response()->json([
            'success' => true,
            'message' => 'Job complete. Collect payment from the customer.',
            'status'  => 'payment_pending',
            'task'    => $this->transformBooking($task),
        ]);
    }

    public function checkPaymentStatus(Request $request, Booking $booking)
    {
        $this->touchPresence();

        $task     = $this->resolveOwnedTask($booking, true);
        $payMongo = app(\App\Services\PayMongoService::class);
        $isPaid   = false;

        if (filled($task->paymongo_intent_id)) {
            $isPaid = $payMongo->isIntentPaid($task->paymongo_intent_id, $task->paymongo_client_key ?? '');
        } elseif (filled($task->paymongo_link_id)) {
            $isPaid = $payMongo->isLinkPaid($task->paymongo_link_id);
        } else {
            return response()->json(['success' => false, 'paid' => false, 'message' => 'No payment found.'], 404);
        }

        if ($isPaid) {
            $task->update([
                'status'         => 'completed',
                'completed_at'   => now(),
                'payment_method' => filled($task->paymongo_intent_id) ? 'card' : 'gcash',
            ]);

            $task->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
            $this->syncAssignedUnitStatus($task, 'available');
            $this->teamLeaderAvailability->setOperationalOverride(Auth::user(), 'available');
            event(new BookingStatusUpdated($task));

            return response()->json([
                'success'      => true,
                'paid'         => true,
                'message'      => 'Payment confirmed! Job is now complete.',
                'task'         => $this->transformBooking($task),
                'redirect_url' => route('teamleader.tasks'),
            ]);
        }

        return response()->json([
            'success' => true,
            'paid'    => false,
            'status'  => $task->status,
        ]);
    }

    public function submitPayment(Request $request, Booking $booking)
    {
        $this->touchPresence();

        $task = $this->resolveOwnedTask($booking, true);

        if ($task->status !== 'payment_pending') {
            return response()->json([
                'success' => false,
                'message' => 'Payment can only be submitted while the task is in payment pending state.',
            ], 422);
        }

        $validated = $request->validate([
            'payment_method' => 'required|in:gcash,bank,cash,cheque',
            'payment_proof'  => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $method = $validated['payment_method'];

        if (in_array($method, ['gcash', 'bank', 'cheque'], true) && ! $request->hasFile('payment_proof')) {
            return response()->json([
                'success' => false,
                'message' => 'Please upload a photo of the payment proof.',
            ], 422);
        }

        $path = $request->hasFile('payment_proof')
            ? $request->file('payment_proof')->store('payment-proofs', 'public')
            : null;

        $task->update([
            'payment_method'       => $method,
            'payment_proof_path'   => $path,
            'payment_submitted_at' => now(),
            'status'               => 'payment_submitted',
        ]);

        $task->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
        event(new BookingStatusUpdated($task));

        return response()->json([
            'success' => true,
            'message' => 'Payment proof submitted. Waiting for dispatcher to confirm.',
            'status'  => $task->status,
            'task'    => $this->transformBooking($task),
        ]);
    }

    public function returnTask(Request $request, Booking $booking)
    {
        $this->touchPresence();

        $task = $this->resolveOwnedTask($booking, true);

        if ($task->status === 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'This task can no longer be returned after the job has started.',
            ], 422);
        }

        if (! in_array($task->status, ['assigned', 'on_the_way'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This task can no longer be returned from its current state.',
            ], 422);
        }

        $validated = $request->validate([
            'return_reason_code' => ['required', 'string', Rule::in(array_column(ReturnReason::cases(), 'value'))],
            'return_reason_note' => 'nullable|string|max:1000',
        ]);

        $reasonEnum = ReturnReason::from($validated['return_reason_code']);
        $note = trim(strip_tags((string) ($validated['return_reason_note'] ?? '')));

        if ($reasonEnum->requiresNote() && blank($note)) {
            return response()->json([
                'success' => false,
                'message' => 'Additional details are required for this return reason.',
            ], 422);
        }

        if ($reasonEnum->requiresNote() && strlen($note) < $reasonEnum->minNoteLength()) {
            return response()->json([
                'success' => false,
                'message' => "Please provide at least {$reasonEnum->minNoteLength()} characters of explanation.",
            ], 422);
        }

        $returnReasonText = $reasonEnum->label();
        if (filled($note)) {
            $returnReasonText .= ': ' . $note;
        }

        $task->update([
            'assigned_team_leader_id' => null,
            'status' => 'assigned',
            'completion_requested_at' => null,
            'customer_verified_at' => null,
            'customer_verification_status' => null,
            'returned_at' => now(),
            'return_reason' => $returnReasonText,
            'returned_by_team_leader_id' => Auth::id(),
        ]);

        $task->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader', 'returnedByTeamLeader']);

        $this->processReturnReasonActions($task, $reasonEnum, $note);

        event(new BookingStatusUpdated($task));

        return response()->json([
            'success' => true,
            'message' => 'Task returned to dispatch for reassignment.',
            'status' => 'assigned',
            'return_reason' => $returnReasonText,
            'redirect_url' => route('teamleader.tasks'),
        ]);
    }

    protected function processReturnReasonActions(Booking $task, ReturnReason $reason, string $note): void
    {
        if ($reason->shouldMarkUnitUnavailable() && $task->assigned_unit_id) {
            Unit::whereKey($task->assigned_unit_id)->update(['status' => 'unavailable']);
            Log::info("Unit {$task->assigned_unit_id} marked unavailable due to vehicle issue", [
                'booking_id' => $task->id,
                'team_leader_id' => Auth::id(),
                'note' => $note,
            ]);
        } else {
            $this->syncAssignedUnitStatus($task, 'available');
        }

        if ($reason->shouldMarkTLUnavailable()) {
            $this->teamLeaderAvailability->setOperationalOverride(Auth::user(), 'unavailable');
            Log::warning("Team leader marked unavailable due to emergency", [
                'team_leader_id' => Auth::id(),
                'booking_id' => $task->id,
                'note' => $note,
            ]);
        } else {
            $this->teamLeaderAvailability->setOperationalOverride(Auth::user(), 'available');
        }

        if ($reason->shouldChargeServiceFee()) {
            Log::info("Service fee should be charged for customer cancellation", [
                'booking_id' => $task->id,
                'customer_id' => $task->customer_id,
            ]);
        }

        if ($reason->requiresDispatcherDecision()) {
            Log::notice("Dispatcher decision required for return reason: {$reason->label()}", [
                'booking_id' => $task->id,
                'reason' => $reason->value,
                'priority' => $reason->priority(),
                'note' => $note,
            ]);
        }

        if ($reason->requiresRequote()) {
            Log::info("Booking requires re-quotation due to wrong vehicle info", [
                'booking_id' => $task->id,
                'note' => $note,
            ]);
        }

        Log::info("Task returned to dispatch", [
            'booking_id' => $task->id,
            'reason_code' => $reason->value,
            'reason_label' => $reason->label(),
            'priority' => $reason->priority(),
            'auto_reassign' => $reason->shouldAutoReassign(),
            'team_leader_id' => Auth::id(),
        ]);
    }

    public function confirmCompletion(Request $request, Booking $booking)
    {
        return $this->completeTask($request, $booking);
    }

    public function taskStatus(Booking $booking)
    {
        $this->touchPresence();

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
                'status' => 'payment_pending',
                'customer_verification_status' => 'approved',
                'customer_verified_at' => now(),
            ]);

            $booking->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
            event(new BookingStatusUpdated($booking));

            return response($this->verificationResponseHtml(
                'Service confirmed — thank you!',
                'Your confirmation has been received. The team leader will now process your payment and submit proof to the dispatcher to finalize the job.'
            ))->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $booking->update([
            'status' => 'in_progress',
            'customer_verification_status' => 'rejected',
            'customer_verified_at' => null,
        ]);

        $booking->refresh()->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);
        $this->syncAssignedUnitStatus($booking, 'on_job');
        event(new BookingStatusUpdated($booking));

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
        $userUnit = Auth::user()?->unit;

        return Booking::with(['customer', 'truckType', 'unit.driver', 'unit.teamLeader', 'assignedTeamLeader'])
            ->whereIn('status', ['assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'payment_pending', 'payment_submitted', 'completed'])
            ->whereNotNull('assigned_unit_id')
            ->whereNotNull('assigned_team_leader_id')
            ->where('assigned_team_leader_id', $teamLeaderId);
    }

    protected function queueBookingsQuery(): Builder
    {
        $teamLeaderId = Auth::id();
        $userUnit = Auth::user()?->unit;

        return Booking::with(['customer', 'truckType', 'unit.driver', 'unit.teamLeader', 'assignedTeamLeader'])
            ->whereIn('status', ['assigned', 'on_the_way', 'in_progress']) // ONLY READY TASKS
            ->whereNotNull('assigned_unit_id')
            ->whereNotNull('assigned_team_leader_id')
            ->whereNull('returned_at')
            ->where('assigned_team_leader_id', $teamLeaderId);
    }

    protected function ownedTasksQuery(bool $includeCompleted = false): Builder
    {
        $statuses = $includeCompleted
            ? array_values(array_unique([...$this->activeStatuses, 'completed']))
            : $this->activeStatuses;

        return Booking::with(['customer', 'truckType', 'unit.driver', 'unit.teamLeader', 'assignedTeamLeader'])
            ->where('assigned_team_leader_id', Auth::id())
            ->whereIn('status', $statuses)
            ->latest('updated_at');
    }

    protected function resolveOwnedTask(Booking $booking, bool $includeCompleted = false): Booking
    {
        $task = $this->ownedTasksQuery($includeCompleted)->find($booking->id);

        if ($task) {
            return $task;
        }

        // Fallback: booking is pre-assigned to this TL but still in `confirmed` status
        // (dispatcher set assigned_team_leader_id when sending the quotation and the TL
        // has not yet formally accepted).  Auto-advance it to `assigned` so every
        // downstream action (saveDriver, proceedToLocation, etc.) works normally.
        $confirmedTask = Booking::with(['customer', 'truckType', 'unit', 'assignedTeamLeader'])
            ->whereKey($booking->id)
            ->where('assigned_team_leader_id', Auth::id())
            ->where('status', 'confirmed')
            ->first();

        if ($confirmedTask) {
            $assignedUnitId = $confirmedTask->assigned_unit_id ?: optional(Auth::user()->unit)->id;

            Booking::query()
                ->whereKey($confirmedTask->id)
                ->where('assigned_team_leader_id', Auth::id())
                ->where('status', 'confirmed')
                ->update([
                    'status'     => 'assigned',
                    'assigned_unit_id' => $assignedUnitId,
                    'assigned_at' => now(),
                    'customer_verification_status' => null,
                    'customer_verified_at' => null,
                    'completion_requested_at' => null,
                    'customer_verification_note' => null,
                ]);

            $confirmedTask = $this->ownedTasksQuery($includeCompleted)->find($booking->id);

            if ($confirmedTask) {
                $this->syncAssignedUnitStatus($confirmedTask, 'on_job');
                event(new BookingStatusUpdated($confirmedTask));

                return $confirmedTask;
            }
        }

        if (request()->expectsJson() || request()->ajax()) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'This task is no longer assigned to you.',
                'redirect_url' => route('teamleader.tasks'),
            ], 409));
        }

        throw new HttpResponseException(
            redirect()->route('teamleader.tasks')
                ->with('info', 'This task is no longer assigned to you.')
        );
    }

    protected function syncAssignedUnitStatus(Booking $booking, string $status): void
    {
        $unitId = $booking->assigned_unit_id ?: optional($booking->unit)->id ?: optional(Auth::user()->unit)->id;

        if (! $unitId) {
            return;
        }

        Unit::query()
            ->whereKey($unitId)
            ->update(['status' => $status]);
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
        $uiStatus = $booking->needs_reassignment
            ? 'returned'
            : match ($booking->status) {
                'confirmed', 'accepted', 'assigned', 'quotation_sent' => 'assigned',
                'on_the_way'          => 'on_the_way',
                'in_progress'         => 'in_progress',
                'waiting_verification' => 'waiting_verification',
                'payment_pending'     => 'payment_pending',
                'payment_submitted'   => 'payment_submitted',
                'completed'           => 'completed',
                default               => $booking->status,
            };

        $distanceKm   = (float) ($booking->distance_km ?? 0);
        $kmIncrements = (int) floor($distanceKm / 4);
        $distanceFee  = round($kmIncrements * 200.0, 2);
        $baseRate     = (float) ($booking->base_rate ?? 0);
        $additionalFee = (float) ($booking->additional_fee ?? 0);
        $finalTotal   = (float) ($booking->final_total ?? 0);

        $unitDriver  = $booking->unit?->driver?->full_name
            ?? $booking->unit?->driver?->name
            ?? $booking->unit?->driver_name
            ?? '—';
        $tlName = $booking->assignedTeamLeader?->full_name
            ?? $booking->assignedTeamLeader?->name
            ?? $booking->unit?->teamLeader?->full_name
            ?? '—';

        $serviceLabel = match ($booking->service_type) {
            'book_now'  => 'Book Now',
            'scheduled' => 'Scheduled',
            default     => 'Book Now',
        };

        $paymentMethodLabel = match ($booking->payment_method) {
            'gcash'   => 'GCash',
            'bank'    => 'Bank Transfer',
            'cash'    => 'Cash',
            'cheque'  => 'Cheque',
            default   => null,
        };

        return [
            // Identifiers
            'id'              => $booking->booking_code ?: $booking->id,
            'booking_code'    => $booking->job_code,
            'status'          => $booking->status,
            'ui_status'       => $uiStatus,

            // Status labels
            'status_label' => match ($uiStatus) {
                'returned'             => 'Returned to Dispatch',
                'assigned'             => 'Ready',
                'on_the_way'           => 'On the Way',
                'in_progress'          => 'Towing in Progress',
                'waiting_verification' => 'Awaiting Customer Confirmation',
                'payment_pending'      => 'Collect Payment',
                'payment_submitted'    => 'Awaiting Dispatcher Confirmation',
                'completed'            => 'Completed',
                default                => ucfirst(str_replace('_', ' ', $booking->status)),
            },
            'status_note' => match ($uiStatus) {
                'returned'             => 'This task was returned to dispatch and is waiting for reassignment.',
                'assigned'             => 'Your crew is assigned. Head to the pickup location when ready.',
                'on_the_way'           => 'Your crew is heading to the pickup location now.',
                'in_progress'          => 'The tow is underway. Complete the job when finished.',
                'waiting_verification' => 'A confirmation request has been sent to the customer. This page updates automatically.',
                'payment_pending'      => 'Customer confirmed the service. Collect payment and upload the proof.',
                'payment_submitted'    => 'Payment proof submitted. Waiting for dispatcher to confirm.',
                'completed'            => 'Job complete. A receipt has been sent to the customer.',
                default                => 'Job updated.',
            },

            // Customer
            'customer_name'  => $booking->customer?->full_name ?? $booking->customer?->name ?? 'Guest',
            'customer_phone' => $booking->customer?->phone ?? 'N/A',
            'customer_email' => $booking->customer?->email ?? '—',

            // Service
            'pickup_address'  => $booking->pickup_address ?? '—',
            'dropoff_address' => $booking->dropoff_address ?? '—',
            'distance_km'     => $distanceKm,

            // Pricing
            'base_rate'      => $baseRate,
            'km_increments'  => $kmIncrements,
            'distance_fee'   => $distanceFee,
            'additional_fee' => $additionalFee,
            'final_total'    => $finalTotal,

            // Booking info
            'service_type'     => $booking->service_type ?? 'book_now',
            'service_label'    => $serviceLabel,
            'quotation_number' => $booking->quotation_number ?? $booking->job_code,
            'scheduled_for'    => optional($booking->scheduled_for)->format('M d, Y h:i A'),

            // Assigned truck
            'truck_type'  => $booking->truckType?->name ?? 'General Towing',
            'unit_name'   => $booking->unit?->name ?? 'Dispatch-assigned unit',
            'unit_plate'  => $booking->unit?->plate_number ?? '—',
            'unit_driver' => $unitDriver,
            'tl_name'     => $tlName,

            // Payment
            'payment_method'        => $booking->payment_method,
            'payment_method_label'  => $paymentMethodLabel,
            'payment_proof_path'    => $booking->payment_proof_path
                ? \Illuminate\Support\Facades\Storage::disk('public')->url($booking->payment_proof_path)
                : null,
            'payment_submitted_at'  => optional($booking->payment_submitted_at)->diffForHumans(),
            'paymongo_link_id'      => $booking->paymongo_link_id,
            'paymongo_checkout_url' => $booking->paymongo_checkout_url,
            'paymongo_intent_id'    => $booking->paymongo_intent_id,
            'payment_method_type'   => $booking->paymongo_intent_id ? 'card' : ($booking->paymongo_link_id ? 'gcash' : null),

            // Timestamps
            'updated_at_human' => optional($booking->updated_at)->diffForHumans() ?? 'Just now',
            'completion_note'  => $booking->customer_verification_note,
            'return_reason'    => $booking->return_reason,

            // Flags
            'is_returned'    => $booking->needs_reassignment,
            'assigned_to_me' => (int) $booking->assigned_team_leader_id === (int) Auth::id(),
            'can_accept' => ! $booking->needs_reassignment
                && in_array($booking->status, ['quotation_sent', 'confirmed', 'accepted', 'assigned'], true)
                && (empty($booking->assigned_team_leader_id) || (int) $booking->assigned_team_leader_id === (int) Auth::id()),
            'can_open'            => (int) $booking->assigned_team_leader_id === (int) Auth::id() && $booking->status !== 'completed',
            'can_proceed'         => $booking->status === 'assigned',
            'can_start'           => $booking->status === 'on_the_way',
            'can_complete'        => $booking->status === 'in_progress',
            'can_return'          => in_array($booking->status, ['assigned', 'on_the_way'], true),
            'is_waiting'          => $booking->status === 'waiting_verification',
            'is_payment_pending'  => $booking->status === 'payment_pending',
            'is_payment_submitted' => $booking->status === 'payment_submitted',
            'is_completed'        => $booking->status === 'completed',
            'completion_note_locked' => $booking->status !== 'in_progress',

            // URLs
            'task_url'   => route('teamleader.task.show', $booking),
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
