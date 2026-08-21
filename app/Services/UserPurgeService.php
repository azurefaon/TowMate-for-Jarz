<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Receipt;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserPurgeService
{
    /**
     * Permanently delete a user, or anonymize it if receipt/booking history
     * blocks a real delete. Returns 'deleted' or 'anonymized'.
     */
    public function purge(User $user, bool $automatic = true): string
    {
        Unit::where('team_leader_id', $user->id)
            ->orWhere('driver_id', $user->id)
            ->update(['team_leader_id' => null, 'driver_id' => null]);

        Booking::where('created_by_admin_id', $user->id)
            ->update(['created_by_admin_id' => null]);

        $stillBlocked = Receipt::where('generated_by', $user->id)->exists()
            || DB::table('booking_assignments')->where('dispatcher_id', $user->id)->exists();

        $actorId = $automatic ? null : Auth::id();

        if (! $stillBlocked) {
            $reference = $user->name;
            $entityId = $user->id;

            $user->delete();

            AuditLog::create([
                'user_id' => $actorId,
                'action' => 'user_permanently_deleted',
                'entity_type' => 'User',
                'entity_id' => $entityId,
                'reference' => $reference,
                'description' => $automatic
                    ? 'Purged automatically after Deleted-list retention period expired.'
                    : 'Purged immediately from the Deleted list.',
            ]);

            return 'deleted';
        }

        $user->update([
            'first_name' => 'Deleted',
            'middle_name' => null,
            'last_name' => "User #{$user->id}",
            'name' => "Deleted User #{$user->id}",
            'email' => "deleted-{$user->id}@removed.local",
            'phone' => null,
            'password' => Hash::make(Str::random(40)),
            'pending_delete_at' => null,
        ]);

        AuditLog::create([
            'user_id' => $actorId,
            'action' => 'user_anonymized',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'reference' => "Deleted User #{$user->id}",
            'description' => 'Could not be permanently deleted (receipt/booking history on record) — personal data anonymized instead.',
        ]);

        return 'anonymized';
    }
}
