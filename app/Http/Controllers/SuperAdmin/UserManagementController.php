<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Unit;
use App\Models\User;
use App\Services\TeamLeaderAvailabilityService;
use App\Services\UserPurgeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserManagementController extends Controller
{
    /**
     * $bucket: null = active users, 'archived' = hidden but restorable,
     * 'deleted' = queued for permanent removal (pending_delete_at set).
     * Anonymized accounts are excluded from every bucket, see below.
     */
    protected function baseUserQuery(?string $bucket = null)
    {
        return User::with('role')
            ->whereHas('role', function ($q) {
                $q->whereNotIn('id', [1]);
            })
            // Anonymized accounts (permanently processed, blocked from a real hard
            // delete by receipt/dispatch history) never appear in any admin list —
            // not active, not archived, not pending deletion.
            ->whereNull('anonymized_at')
            ->when(
                $bucket === 'archived',
                fn($query) => $query->whereNotNull('archived_at')->whereNull('pending_delete_at')
            )
            ->when(
                $bucket === 'deleted',
                fn($query) => $query->whereNotNull('pending_delete_at')
            )
            ->when(
                $bucket === null,
                fn($query) => $query->whereNull('archived_at')->whereNull('pending_delete_at')
            );
    }

    protected function applyFilters($query, Request $request)
    {
        if ($request->filled('search')) {
            $query->where(function ($subQuery) use ($request) {
                $subQuery->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('role')) {
            $query->where('role_id', $request->role);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return $query;
    }

    protected function applySort($query, Request $request)
    {
        return match ($request->input('sort')) {
            'name_asc'  => $query->orderBy('name', 'asc'),
            'name_desc' => $query->orderBy('name', 'desc'),
            'oldest'    => $query->oldest(),
            'role'      => $query->join('roles', 'roles.id', '=', 'users.role_id')
                                  ->orderBy('roles.name')
                                  ->select('users.*'),
            default     => $query->latest(),
        };
    }

    protected function getUserStats(): array
    {
        $baseQuery = User::whereHas('role', function ($q) {
            $q->whereNotIn('id', [1]);
        });

        return [
            'total' => (clone $baseQuery)->whereNull('archived_at')->whereNull('pending_delete_at')->count(),
            'active' => (clone $baseQuery)->whereNull('archived_at')->whereNull('pending_delete_at')->where('status', 'active')->count(),
            'inactive' => (clone $baseQuery)->whereNull('archived_at')->whereNull('pending_delete_at')->where('status', 'inactive')->count(),
            'archived' => (clone $baseQuery)->whereNotNull('archived_at')->whereNull('pending_delete_at')->count(),
            'deleted' => (clone $baseQuery)->whereNotNull('pending_delete_at')->count(),
            'password_requests' => (clone $baseQuery)->whereNull('archived_at')->whereNull('pending_delete_at')->where('password_request_status', 'pending')->count(),
        ];
    }

    protected function normalizeUserInput(Request $request): void
    {
        $nameParts = split_full_name($request->input('name'));
        $firstName  = trim((string) ($request->input('first_name')  ?: $nameParts['first_name']));
        $middleName = trim((string) ($request->input('middle_name') ?: $nameParts['middle_name']));
        $lastName   = trim((string) ($request->input('last_name')   ?: $nameParts['last_name']));

        // Strip non-digits, then normalize 9XXXXXXXXX → 09XXXXXXXXX
        $rawPhone = trim((string) ($request->input('phone') ?? ''));
        $phone    = preg_replace('/\D/', '', $rawPhone);
        if (preg_match('/^9[1-9]\d{8}$/', $phone)) {
            $phone = '0' . $phone; // prepend leading 0 for consistent storage
        }

        $request->merge([
            'first_name'  => $firstName  !== '' ? $firstName  : null,
            'middle_name' => $middleName !== '' ? $middleName : null,
            'last_name'   => $lastName   !== '' ? $lastName   : null,
            'name'        => build_full_name($firstName, $middleName, $lastName),
            'email'       => strtolower(trim((string) $request->input('email'))),
            'phone'       => $phone !== '' ? $phone : null,
        ]);
    }

    protected function emailRules(?User $user = null): array
    {
        $rules = [
            'required',
            'email:rfc',
            'max:150',
            function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_public_email((string) $value)) {
                    $fail('Email must be valid and able to receive system notifications and receipts.');
                }
            },
        ];

        $rules[] = $user
            ? Rule::unique('users', 'email')->ignore($user->id)
            : 'unique:users,email';

        return $rules;
    }

    protected function manageableRoles()
    {
        return Role::whereNotIn('id', [1, 4])
            ->orderBy('name')
            ->get();
    }

    protected function teamLeaderCapacity(): array
    {
        $teamLeaderRole = Role::query()->where('name', 'Team Leader')->first();
        $limit = max((int) SystemSetting::getValue('max_team_leaders', 10), 1);
        $count = $teamLeaderRole
            ? User::query()->where('role_id', $teamLeaderRole->id)->whereNull('archived_at')->count()
            : 0;

        return [
            'role_id' => $teamLeaderRole?->id,
            'limit' => $limit,
            'count' => $count,
            'remaining' => max($limit - $count, 0),
            'reached' => $count >= $limit,
        ];
    }

    protected function isDispatcherOnline(?User $user): bool
    {
        return (bool) $user
            && (int) $user->role_id === 2
            && Cache::has('dispatcher:presence:' . $user->id);
    }

    /**
     * Same "currently active job" status set already used by
     * ControlCenterService/MonitoringController for consistency.
     */
    protected function hasActiveAssignment(User $user): bool
    {
        return Booking::where('assigned_team_leader_id', $user->id)
            ->whereIn('status', ['accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'on_job'])
            ->exists();
    }

    public function index(Request $request)
    {
        $users = $this->applySort(
            $this->applyFilters($this->baseUserQuery(), $request),
            $request
        )->paginate(10);

        // Which team leaders currently have a live job — drives the blue "busy"
        // presence dot on their avatar (green/gray/blue), see partials/table.blade.php.
        $busyTeamLeaderIds = app(TeamLeaderAvailabilityService::class)->busyTeamLeaderIds();

        if ($request->ajax()) {
            return view('superadmin.users.partials.table', compact('users', 'busyTeamLeaderIds'))->render();
        }

        $passwordRequests = $this->baseUserQuery()
            ->where('password_request_status', 'pending')
            ->orderByDesc('password_requested_at')
            ->get();

        $roles = $this->manageableRoles();
        $stats = $this->getUserStats();

        return view('superadmin.users.index', compact('users', 'roles', 'stats', 'passwordRequests', 'busyTeamLeaderIds'));
    }

    public function archived(Request $request)
    {
        $archivedUsers = $this->applyFilters($this->baseUserQuery('archived'), $request)
            ->orderByDesc('archived_at')
            ->paginate(10)
            ->appends($request->query());

        if ($request->ajax()) {
            return view('superadmin.users.partials.archived-table', compact('archivedUsers'))->render();
        }

        $roles = $this->manageableRoles();
        $stats = $this->getUserStats();

        return view('superadmin.users.archived', compact('archivedUsers', 'roles', 'stats'));
    }

    public function deleted(Request $request)
    {
        $deletedUsers = $this->applyFilters($this->baseUserQuery('deleted'), $request)
            ->orderByDesc('pending_delete_at')
            ->paginate(10)
            ->appends($request->query());

        $retentionDays = max((int) SystemSetting::getValue('deleted_retention_days', 30), 1);

        if ($request->ajax()) {
            return view('superadmin.users.partials.deleted-table', compact('deletedUsers', 'retentionDays'))->render();
        }

        $roles = $this->manageableRoles();
        $stats = $this->getUserStats();

        return view('superadmin.users.deleted', compact('deletedUsers', 'roles', 'stats', 'retentionDays'));
    }

    public function edit($id)
    {
        $user = User::with(['unit', 'unit.truckType'])->findOrFail($id);

        if ($user->role->name === 'Customer') {
            abort(403, 'Customer accounts cannot be edited from this panel.');
        }

        $roles = $this->manageableRoles();
        $teamLeaderCapacity = $this->teamLeaderCapacity();

        return view('superadmin.users.create', compact('user', 'roles', 'teamLeaderCapacity'));
    }

    public function update(Request $request, User $user)
    {
        $this->normalizeUserInput($request);

        if ((int) $user->role_id === 1) {
            abort(403, 'Cannot modify the Owner account.');
        }

        if ($user->role->name === 'Customer') {
            abort(403, 'Customer accounts cannot be edited from this panel.');
        }

        $isTeamLeaderEdit = $user->role->name === 'Team Leader';

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'required|string|max:100',
            'driver_first_name' => 'nullable|string|max:100',
            'driver_middle_name' => 'nullable|string|max:100',
            'driver_last_name' => 'nullable|string|max:100',
            'crew_member_1_name' => 'nullable|string|max:150',
            'crew_member_2_name' => 'nullable|string|max:150',
            'phone' => $isTeamLeaderEdit
                ? ['required', 'regex:/^09[1-9]\d{8}$/', Rule::unique('users', 'phone')->ignore($user->id)]
                : ['nullable', 'regex:/^09[1-9]\d{8}$/', Rule::unique('users', 'phone')->ignore($user->id)],
            'role_id' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                    if ($value !== null && (int) $value !== (int) $user->role_id) {
                        $fail('Role cannot be changed after user creation.');
                    }
                },
            ],
        ], [
            'phone.required'           => 'Phone number is required for Team Leader accounts.',
            'phone.regex'              => 'Enter a valid Philippine mobile number starting with 9 or 09.',
            'phone.unique'             => 'This phone number is already registered to another user.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $userUpdates = [
            'name' => build_full_name(
                $validated['first_name'],
                $validated['middle_name'] ?? null,
                $validated['last_name']
            ),
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'phone' => $validated['phone'] ?? $user->phone,
        ];

        if ($isTeamLeaderEdit) {
            $userUpdates['driver_first_name'] = $validated['driver_first_name'] ?? null;
            $userUpdates['driver_middle_name'] = $validated['driver_middle_name'] ?? null;
            $userUpdates['driver_last_name'] = $validated['driver_last_name'] ?? null;
            $userUpdates['crew_member_1_name'] = $validated['crew_member_1_name'] ?? null;
            $userUpdates['crew_member_2_name'] = $validated['crew_member_2_name'] ?? null;
        }

        $user->update($userUpdates);

        if ($request->filled('password')) {
            $request->validate([
                'password' => ['nullable', Password::min(12)->mixedCase()->numbers()]
            ]);

            $user->update([
                'password' => Hash::make($request->password)
            ]);
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'user_updated',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'reference' => $user->name,
            'description' => 'Profile details updated.',
        ]);

        return response()->json([
            'success' => true,
            'requires_relogin' => false,
            'message' => '',
        ]);
    }

    public function create()
    {
        $roles = $this->manageableRoles();
        $teamLeaderCapacity = $this->teamLeaderCapacity();

        return view('superadmin.users.create', compact('roles', 'teamLeaderCapacity'));
    }

    public function store(Request $request)
    {
        $this->normalizeUserInput($request);

        $teamLeaderCapacity = $this->teamLeaderCapacity();
        $teamLeaderRoleId   = (int) ($teamLeaderCapacity['role_id'] ?? 0);
        $isTeamLeader       = $teamLeaderRoleId > 0
            && (int) $request->input('role_id', 0) === $teamLeaderRoleId;

        $rules = [
            'first_name'  => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name'   => 'required|string|max:100',
            'email'       => $this->emailRules(),
            'phone'       => $isTeamLeader
                ? ['required', 'regex:/^09[1-9]\d{8}$/', 'unique:users,phone']
                : ['nullable', 'regex:/^09[1-9]\d{8}$/', 'unique:users,phone'],
            'password'    => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
            'role_id'     => [
                'required',
                'exists:roles,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($teamLeaderCapacity) {
                    if (
                        $teamLeaderCapacity['role_id']
                        && (int) $value === (int) $teamLeaderCapacity['role_id']
                        && $teamLeaderCapacity['reached']
                    ) {
                        $fail('Team Leader limit reached. Increase the maximum in System Settings before creating another Team Leader account.');
                    }
                },
            ],
        ];

        if ($isTeamLeader) {
            $rules = array_merge($rules, [
                'driver_first_name'  => 'required|string|max:100',
                'driver_middle_name' => 'nullable|string|max:100',
                'driver_last_name'   => 'required|string|max:100',
                'crew_member_1_name' => 'nullable|string|max:150',
                'crew_member_2_name' => 'nullable|string|max:150',
            ]);
        }

        $messages = [
            'email.required'               => 'Email is required.',
            'email.unique'                 => 'This email is already registered.',
            'phone.required'               => 'Phone number is required for Team Leader accounts.',
            'phone.regex'                  => 'Enter a valid Philippine mobile number starting with 9 or 09 (e.g. 09171234567).',
            'phone.unique'                 => 'This phone number is already registered to another user.',
            'password.confirmed'           => 'Password confirmation does not match.',
        ];

        $validated = $request->validate($rules, $messages);

        if ($isTeamLeader) {
            $teamLeader = User::create([
                'name'                 => build_full_name($validated['first_name'], $validated['middle_name'] ?? null, $validated['last_name']),
                'first_name'           => $validated['first_name'],
                'middle_name'          => $validated['middle_name'] ?? null,
                'last_name'            => $validated['last_name'],
                'email'                => $validated['email'],
                'phone'                => $validated['phone'] ?? null,
                'password'             => Hash::make($validated['password']),
                'role_id'              => $validated['role_id'],
                'driver_first_name'    => $validated['driver_first_name'] ?? null,
                'driver_middle_name'   => $validated['driver_middle_name'] ?? null,
                'driver_last_name'     => $validated['driver_last_name'] ?? null,
                'crew_member_1_name'   => $validated['crew_member_1_name'] ?? null,
                'crew_member_2_name'   => $validated['crew_member_2_name'] ?? null,
                'status'               => 'active',
                'must_change_password' => true,
            ]);

            $tlRoleName = Role::find($validated['role_id'])?->name ?? 'Team Leader';

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'user_registered',
                'entity_type' => 'User',
                'entity_id' => $teamLeader->id,
                'reference' => $teamLeader->name,
                'description' => $tlRoleName . ' — no truck assigned yet, add one in Fleet Management → Trucks.',
            ]);
        } else {
            $user = User::create([
                'name'                 => build_full_name($validated['first_name'], $validated['middle_name'] ?? null, $validated['last_name']),
                'first_name'           => $validated['first_name'],
                'middle_name'          => $validated['middle_name'] ?? null,
                'last_name'            => $validated['last_name'],
                'email'                => $validated['email'],
                'phone'                => $validated['phone'] ?? null,
                'password'             => Hash::make($validated['password']),
                'role_id'              => $validated['role_id'],
                'status'               => 'active',
                'must_change_password' => true,
            ]);

            $role = Role::find($validated['role_id']);

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'user_registered',
                'entity_type' => 'User',
                'entity_id' => $user->id,
                'reference' => $user->name,
                'description' => $role->name ?? 'User',
            ]);
        }

        return redirect()
            ->route('superadmin.users.index')
            ->with('success', 'User created successfully.');
    }

    public function toggleStatus($id): RedirectResponse
    {
        $user = User::findOrFail($id);

        if ($user->archived_at || $user->pending_delete_at) {
            return back()->with('error', 'Restore this user from the archive first.');
        }

        if ($user->id == Auth::id()) {
            return back()->with('error', 'Cannot deactivate yourself.');
        }

        if ($this->isDispatcherOnline($user)) {
            return back()->with('error', 'Cannot change status while this dispatcher is currently online.');
        }

        $user->status = $user->status == 'active' ? 'inactive' : 'active';
        $user->forceFill([
            'remember_token' => Str::random(60),
        ])->save();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'Toggled status for: ' . $user->name,
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);

        return back()->with('success', 'User status updated.');
    }

    public function unlockCustomer($id): RedirectResponse
    {
        $user = User::findOrFail($id);

        if ($user->status !== 'locked') {
            return back()->with('error', 'This account is not locked.');
        }

        $user->update([
            'status' => 'active',
            'last_login_at' => now(),
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'customer_unlocked_by_admin',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'reference' => $user->name,
            'description' => 'Unlocked from Manage Users by an admin.',
        ]);

        return back()->with('success', 'Account unlocked.');
    }

    public function archive(Request $request, User $user): RedirectResponse
    {
        if ($user->id === Auth::id()) {
            return back()->with('error', 'You cannot archive your own account.');
        }

        if ((int) ($user->role_id ?? 0) === 1) {
            abort(403, 'Cannot archive the Owner account.');
        }

        if ($this->isDispatcherOnline($user)) {
            return back()->with('error', 'Cannot archive this dispatcher while they are currently online.');
        }

        if ($this->hasActiveAssignment($user)) {
            return back()->with('error', 'Cannot archive this user while they have an active job assignment.');
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        // Release the unit owned by this team leader so it remains visible in the dispatcher
        // as an unassigned unit rather than disappearing entirely.
        if (($user->role->name ?? null) === 'Team Leader') {
            Unit::where('team_leader_id', $user->id)->update([
                'team_leader_id'    => null,
                'dispatcher_status' => null,
                'zone_confirmed'    => false,
            ]);
        }

        $user->update([
            'status'          => 'inactive',
            'archived_at'     => now(),
            'archived_reason' => $validated['reason'],
        ]);

        // Rotate remember_token to terminate any active browser session immediately.
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'user_archived',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'reference' => $user->name,
            'description' => "Moved user to archive panel. Reason: {$validated['reason']}",
        ]);

        return redirect()->route('superadmin.users.index')
            ->with('success', 'User moved to archive successfully.');
    }

    public function restore($id): RedirectResponse
    {
        $user = User::findOrFail($id);

        $user->update([
            'archived_at' => null,
            'archived_reason' => null,
            'pending_delete_at' => null,
            'status' => 'active',
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'user_restored',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'reference' => $user->name,
            'description' => 'Restored user from archive panel',
        ]);

        return redirect()->route('superadmin.users.archived')
            ->with('success', 'User restored successfully.');
    }

    public function queueForDeletion(Request $request, $id): RedirectResponse
    {
        $user = User::findOrFail($id);

        if ($user->id === Auth::id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        if ((int) ($user->role_id ?? 0) === 1) {
            abort(403, 'Cannot delete the Owner account.');
        }

        if ($this->isDispatcherOnline($user)) {
            return back()->with('error', 'Cannot delete this dispatcher while they are currently online.');
        }

        if ($this->hasActiveAssignment($user)) {
            return back()->with('error', 'Cannot delete this user while they have an active job assignment.');
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $user->update([
            'pending_delete_at' => now(),
            'pending_delete_reason' => $validated['reason'],
        ]);

        // Rotate remember_token to terminate any active browser session, and revoke API tokens.
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        $user->tokens()->delete();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'user_queued_for_deletion',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'reference' => $user->name,
            'description' => "Marked for deletion, pending permanent purge. Reason: {$validated['reason']}",
        ]);

        return redirect()->route('superadmin.users.deleted')
            ->with('success', 'User marked for deletion.');
    }

    public function restoreFromDeleted($id): RedirectResponse
    {
        $user = User::findOrFail($id);

        $user->update([
            'pending_delete_at' => null,
            'pending_delete_reason' => null,
            'archived_at' => null,
            'archived_reason' => null,
            'status' => 'active',
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'user_deletion_cancelled',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'reference' => $user->name,
            'description' => 'Deletion cancelled — restored from Users Pending Deletion.',
        ]);

        return redirect()->route('superadmin.users.index')
            ->with('success', 'User restored successfully.');
    }

    public function purgeNow($id, UserPurgeService $purgeService): RedirectResponse
    {
        $user = User::findOrFail($id);

        if (! $user->pending_delete_at) {
            return back()->with('error', 'Only users pending deletion can be purged.');
        }

        $outcome = $purgeService->purge($user, automatic: false);

        return redirect()->route('superadmin.users.deleted')
            ->with('success', $outcome === 'deleted'
                ? 'User permanently deleted.'
                : 'User could not be fully deleted due to receipt/booking history — personal data was anonymized instead.');
    }

    public function setDefaultPassword(Request $request, User $user): RedirectResponse
    {
        if ($user->archived_at || $user->pending_delete_at) {
            return back()->with('error', 'Restore this user before setting a default password.');
        }

        if ((int) ($user->role_id ?? 0) === 1) {
            abort(403, 'Cannot change the Owner account password from this panel.');
        }

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ], [
            'password.required' => 'Enter a default password for this user.',
            'password.confirmed' => 'The password confirmation does not match.',
        ]);

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'password_request_status' => 'resolved',
            'password_request_resolved_at' => now(),
            'password_request_note' => null,
            'remember_token' => Str::random(60),
        ])->save();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'default_password_set',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);

        return redirect()->route('superadmin.users.index')
            ->with('success', 'Default password saved for ' . $user->name . '. Ask the user to sign in and update it after access is restored.');
    }

    public function resolvePasswordRequest(User $user): RedirectResponse
    {
        $user->forceFill([
            'password_request_status' => 'resolved',
            'password_request_resolved_at' => now(),
            'password_request_note' => null,
        ])->save();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'password_request_marked_handled',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);

        return redirect()->route('superadmin.users.index')
            ->with('success', 'Password request for ' . $user->name . ' has been marked as handled.');
    }

    public function destroy(User $user): RedirectResponse
    {
        return $this->archive($user);
    }

}
