<?php

namespace App\Observers;

use App\Models\Booking;
use App\Services\Finance\JobCrewCaptureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feeds Dispatch events into the job crew record. Every assignment path updates the Booking model, so this one
 * observer covers Dispatch, Jobs reassignment, the next-task queue and the Team Leader app.
 *
 * It must never break dispatching: each capture runs in its own savepoint and any failure is reported and ignored.
 */
class JobCrewCaptureObserver
{
    /** The Team Leader accepted or started the job: re-check the crew, because the roster may have changed. */
    private const START_STATUSES = ['accepted', 'on_the_way'];

    /** The job's towing work is done (the Team Leader app sets these). */
    private const DONE_STATUSES = ['waiting_verification', 'payment_pending', 'payment_submitted', 'completed'];

    private static ?bool $tableExists = null;

    public function __construct(private readonly JobCrewCaptureService $capture)
    {
    }

    public function updated(Booking $booking): void
    {
        $unitChanged = $booking->wasChanged('assigned_unit_id');
        $statusChanged = $booking->wasChanged('status');
        if (! $unitChanged && ! $statusChanged) {
            return;
        }

        $this->safely(function () use ($booking, $unitChanged, $statusChanged) {
            if ($unitChanged) {
                $oldUnitId = (int) $booking->getOriginal('assigned_unit_id');
                if ($oldUnitId) {
                    $this->capture->release($booking, $oldUnitId, $booking->assigned_unit_id
                        ? 'The booking was reassigned to another unit before the job.'
                        : 'The booking was returned to Dispatch or cancelled before the job.');
                }
                $this->capture->sync($booking, 'unit assigned');

                return;
            }

            if (in_array($booking->status, self::START_STATUSES, true)) {
                $this->capture->sync($booking, $booking->status === 'accepted' ? 'task accepted' : 'job started');
            } elseif (in_array($booking->status, self::DONE_STATUSES, true) && ! in_array($booking->getOriginal('status'), self::DONE_STATUSES, true)) {
                $actor = auth()->user();
                if ($actor && (int) $actor->id === (int) $booking->assigned_team_leader_id) {
                    $this->capture->confirmTeamLeaderOnCompletion($booking, $actor);
                }
            }
        });
    }

    private function safely(\Closure $work): void
    {
        try {
            if (! (self::$tableExists ??= Schema::hasTable('finance_job_personnel'))) {
                return;
            }
            DB::transaction($work);   // a savepoint when Dispatch is already inside a transaction
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
