<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserManagementController extends Controller
{
    protected function baseUserQuery(bool $archived = false)
    {
        return User::with('role')
            ->whereHas('role', function ($q) {
                $q->whereNotIn('name', ['Super Admin', 'Customer']);
            })
            ->when(
                $archived,
                fn($query) => $query->whereNotNull('archived_at'),
                fn($query) => $query->whereNull('archived_at')
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

    protected function getUserStats(): array
    {
        $baseQuery = User::whereHas('role', function ($q) {
            $q->whereNotIn('name', ['Super Admin', 'Customer']);
        });

        return [
            'total' => (clone $baseQuery)->whereNull('archived_at')->count(),
            'active' => (clone $baseQuery)->whereNull('archived_at')->where('status', 'active')->count(),
            'inactive' => (clone $baseQuery)->whereNull('archived_at')->where('status', 'inactive')->count(),
            'archived' => (clone $baseQuery)->whereNotNull('archived_at')->count(),
            'password_requests' => (clone $baseQuery)->whereNull('archived_at')->where('password_request_status', 'pending')->count(),
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
        return Role::whereNotIn('name', ['Super Admin', 'Driver', 'Customer'])
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

    public function index(Request $request)
    {
        $users = $this->applyFilters($this->baseUserQuery(), $request)
            ->latest()
            ->paginate(10);

        $passwordRequests = $this->baseUserQuery()
            ->where('password_request_status', 'pending')
            ->orderByDesc('password_requested_at')
            ->get();

        $roles = $this->manageableRoles();
        $stats = $this->getUserStats();

        return view('superadmin.users.index', compact('users', 'roles', 'stats', 'passwordRequests'));
    }

    public function archived(Request $request)
    {
        $archivedUsers = $this->applyFilters($this->baseUserQuery(true), $request)
            ->latest('archived_at')
            ->paginate(10);

        $roles = $this->manageableRoles();
        $stats = $this->getUserStats();

        return view('superadmin.users.archived', compact('archivedUsers', 'roles', 'stats'));
    }

    public function edit($id)
    {
        $user = User::with(['unit', 'unit.truckType'])->findOrFail($id);
        $roles = $this->manageableRoles();
        $teamLeaderCapacity = $this->teamLeaderCapacity();
        $truckTypes = TruckType::where('status', 'active')->orderBy('name')->get();

        return view('superadmin.users.create', compact('user', 'roles', 'teamLeaderCapacity', 'truckTypes'));
    }

    public function update(Request $request, User $user)
    {
        $this->normalizeUserInput($request);

        if ($user->role->name === 'Super Admin') {
            abort(403, 'Cannot modify Super Admin.');
        }

        $existingUnitId = $user->unit?->id;
        $isTeamLeaderEdit = $user->role->name === 'Team Leader';

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'required|string|max:100',
            'driver_first_name' => 'nullable|string|max:100',
            'driver_middle_name' => 'nullable|string|max:100',
            'driver_last_name' => 'nullable|string|max:100',
            'email' => $this->emailRules($user),
            'phone' => $isTeamLeaderEdit
                ? ['required', 'regex:/^09[1-9]\d{8}$/', Rule::unique('users', 'phone')->ignore($user->id)]
                : ['nullable', 'regex:/^09[1-9]\d{8}$/', Rule::unique('users', 'phone')->ignore($user->id)],
            'duty_class' => 'nullable|in:light,medium,heavy',
            'status' => 'required|in:active,inactive',
            'unit_plate_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('units', 'plate_number')->ignore($existingUnitId),
            ],
            'unit_truck_id' => [
                'nullable',
                'integer',
                Rule::exists('truck_types', 'id')->where('status', 'active'),
            ],
            'role_id' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                    if ($value !== null && (int) $value !== (int) $user->role_id) {
                        $fail('Role cannot be changed after user creation.');
                    }
                },
            ],
        ], [
            'unit_plate_number.unique' => 'This plate number is already registered to another unit.',
            'unit_truck_id.exists'      => 'Please select a valid truck type.',
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
        $statusChanged = $user->status !== $validated['status'];

        if ($statusChanged && $this->isDispatcherOnline($user)) {
            return response()->json([
                'errors' => [
                    'status' => ['Cannot change status while this dispatcher is currently online.'],
                ],
            ], 422);
        }

        $requiresRelogin = $statusChanged;

        $userUpdates = [
            'name' => build_full_name(
                $validated['first_name'],
                $validated['middle_name'] ?? null,
                $validated['last_name']
            ),
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? $user->phone,
            'status' => $validated['status'],
        ];

        if ($isTeamLeaderEdit && $request->filled('duty_class')) {
            $userUpdates['duty_class'] = $request->input('duty_class');
        }

        $user->update($userUpdates);

        if ($user->role->name === 'Team Leader') {

            $unit = Unit::where('team_leader_id', $user->id)->first();

            if ($unit) {

                if ($request->filled('driver_first_name') || $request->filled('driver_last_name')) {
                    $unit->driver_name = build_full_name(
                        $request->driver_first_name,
                        $request->driver_middle_name,
                        $request->driver_last_name
                    );
                }

                if ($request->filled('unit_name')) {
                    $unit->name = $request->unit_name;
                }

                if ($request->filled('unit_plate_number')) {
                    $unit->plate_number = strtoupper(trim((string) $request->unit_plate_number));
                }

                if ($request->filled('unit_truck_id')) {
                    $truckType = TruckType::where('id', $request->unit_truck_id)
                        ->where('status', 'active')
                        ->first();
                    if ($truckType) {
                        $unit->truck_type_id = $truckType->id;
                    }
                }

                $unit->save();
            }
        }

        if ($request->filled('password')) {
            $request->validate([
                'password' => ['nullable', Password::min(12)->mixedCase()->numbers()]
            ]);

            $user->update([
                'password' => Hash::make($request->password)
            ]);
        }

        if ($requiresRelogin) {
            $user->forceFill([
                'remember_token' => Str::random(60),
            ])->save();
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'user_updated',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'reference' => $user->name,
            'description' => $requiresRelogin
                ? 'Status changed — user should sign in again.'
                : 'Profile details updated.',
        ]);

        return response()->json([
            'success' => true,
            'requires_relogin' => $requiresRelogin,
            'message' => $requiresRelogin
                ? 'User updated. Ask the team member to log out and sign back in so the new access is applied.'
                : '',
        ]);
    }

    public function create()
    {
        $roles = $this->manageableRoles();
        $teamLeaderCapacity = $this->teamLeaderCapacity();

        $truckTypes = TruckType::where('status', 'active')->orderBy('name')->get();

        return view('superadmin.users.create', compact('roles', 'teamLeaderCapacity', 'truckTypes'));
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
            'status' => 'required|in:active,inactive',
        ];

        if ($isTeamLeader) {
            $rules = array_merge($rules, [
                'driver_first_name'  => 'required|string|max:100',
                'driver_middle_name' => 'nullable|string|max:100',
                'driver_last_name'   => 'required|string|max:100',
                'unit_name'          => 'required|string|max:100',
                'unit_plate_number'  => 'required|string|max:50|unique:units,plate_number',
                'unit_truck_id'      => [
                    'required',
                    'integer',
                    Rule::exists('truck_types', 'id')->where('status', 'active'),
                ],
                'duty_class'         => 'required|in:light,medium,heavy',
            ]);
        }

        $messages = [
            'email.required'               => 'Email is required.',
            'email.unique'                 => 'This email is already registered.',
            'phone.required'               => 'Phone number is required for Team Leader accounts.',
            'phone.regex'                  => 'Enter a valid Philippine mobile number starting with 9 or 09 (e.g. 09171234567).',
            'phone.unique'                 => 'This phone number is already registered to another user.',
            'password.confirmed'           => 'Password confirmation does not match.',
            'unit_name.required'           => 'Unit name is required.',
            'unit_plate_number.required'   => 'Plate number is required.',
            'unit_plate_number.unique'     => 'This plate number is already registered.',
            'unit_truck_id.required'       => 'Truck type is required.',
            'unit_truck_id.exists'         => 'Please select a valid active truck type.',
        ];

        $validated = $request->validate($rules, $messages);

        if ($isTeamLeader) {
            DB::transaction(function () use ($validated) {
                $teamLeader = User::create([
                    'name'                 => build_full_name($validated['first_name'], $validated['middle_name'] ?? null, $validated['last_name']),
                    'first_name'           => $validated['first_name'],
                    'middle_name'          => $validated['middle_name'] ?? null,
                    'last_name'            => $validated['last_name'],
                    'email'                => $validated['email'],
                    'phone'                => $validated['phone'] ?? null,
                    'password'             => Hash::make($validated['password']),
                    'role_id'              => $validated['role_id'],
                    'duty_class'           => $validated['duty_class'] ?? null,
                    'status'               => $validated['status'],
                    'must_change_password' => true,
                ]);

                $driverFirst  = trim((string) ($validated['driver_first_name'] ?? ''));
                $driverMiddle = trim((string) ($validated['driver_middle_name'] ?? ''));
                $driverLast   = trim((string) ($validated['driver_last_name'] ?? ''));

                $truckType = TruckType::where('id', $validated['unit_truck_id'])
                    ->where('status', 'active')
                    ->first();

                if (!$truckType) {
                    throw new \Exception('Truck type not configured. Please set rates first.');
                }

                $unit = Unit::create([
                    'name'           => strtoupper(trim((string) $validated['unit_name'])),
                    'plate_number'   => strtoupper(trim((string) $validated['unit_plate_number'])),
                    'truck_type_id'  => $truckType->id,
                    'team_leader_id' => $teamLeader->id,
                    'driver_name'    => build_full_name($driverFirst, $driverMiddle ?: null, $driverLast),
                    'status'         => 'available',
                ]);

                $tlRoleName = Role::find($validated['role_id'])?->name ?? 'Team Leader';

                AuditLog::create([
                    'user_id' => Auth::id(),
                    'action' => 'user_registered',
                    'entity_type' => 'User',
                    'entity_id' => $teamLeader->id,
                    'reference' => $teamLeader->name,
                    'description' => $tlRoleName,
                ]);

                // AuditLog::create([
                //     'user_id' => Auth::id(), 'action' => 'user_registered',
                //     'entity_type' => 'User', 'entity_id' => $driver->id,
                //     'reference' => $driver->name, 'description' => 'Driver (auto-created with Team Leader)',
                // ]);

                AuditLog::create([
                    'user_id' => Auth::id(),
                    'action' => 'driver_added',
                    'entity_type' => 'Unit',
                    'entity_id' => $unit->id,
                    'reference' => $unit->driver_name,
                    'description' => 'Driver assigned to unit (no account)',
                ]);

                AuditLog::create([
                    'user_id' => Auth::id(),
                    'action' => 'unit_created',
                    'entity_type' => 'Unit',
                    'entity_id' => $unit->id,
                    'reference' => $unit->name,
                    'description' => 'Unit auto-created with Team Leader ' . $teamLeader->name,
                ]);
            });
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
                'status'               => $validated['status'],
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

        if ($user->archived_at) {
            return back()->with('error', 'Restore this user from the archive first.');
        }

        if ($user->id == Auth::id()) {
            return back()->with('error', 'Cannot deactivate yourself.');
        }

        if ($this->isDispatcherOnline($user)) {
            return back()->with('error', 'Cannot change status while this dispatcher is currently online.');
        }

        $user->status = $user->status == 'active' ? 'inactive' : 'active';
        $user->save();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'Toggled status for: ' . $user->name,
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);

        return back()->with('success', 'User status updated.');
    }

    public function archive(User $user): RedirectResponse
    {
        if ($user->id === Auth::id()) {
            return back()->with('error', 'You cannot archive your own account.');
        }

        if (($user->role->name ?? null) === 'Super Admin') {
            abort(403, 'Cannot archive Super Admin.');
        }

        if ($this->isDispatcherOnline($user)) {
            return back()->with('error', 'Cannot archive this dispatcher while they are currently online.');
        }

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
            'status'      => 'inactive',
            'archived_at' => now(),
        ]);

        // Rotate remember_token to terminate any active browser session immediately.
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'user_archived',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'reference' => $user->name,
            'description' => 'Moved user to archive panel',
        ]);

        return redirect()->route('superadmin.users.index')
            ->with('success', 'User moved to archive successfully.');
    }

    public function restore($id): RedirectResponse
    {
        $user = User::findOrFail($id);

        $user->update([
            'archived_at' => null,
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

    public function forceDelete($id): RedirectResponse
    {
        $user = User::findOrFail($id);

        if (! $user->archived_at) {
            return back()->with('error', 'Only archived users can be permanently deleted.');
        }

        if ($user->archived_at->gt(now()->subYear())) {
            return back()->with('error', 'Users must stay archived for at least 1 year before permanent deletion.');
        }

        $reference = $user->name;
        $entityId = $user->id;

        $user->delete();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'user_permanently_deleted',
            'entity_type' => 'User',
            'entity_id' => $entityId,
            'reference' => $reference,
            'description' => 'Archived user permanently deleted after retention window',
        ]);

        return redirect()->route('superadmin.users.archived')
            ->with('success', 'Archived user permanently deleted.');
    }

    public function setDefaultPassword(Request $request, User $user): RedirectResponse
    {
        if ($user->archived_at) {
            return back()->with('error', 'Restore this user before setting a default password.');
        }

        if (($user->role->name ?? null) === 'Super Admin') {
            abort(403, 'Cannot change Super Admin password from this panel.');
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

    public function toggle(User $user): RedirectResponse
    {
        if ($user->archived_at) {
            return back()->with('error', 'Restore this user before changing status.');
        }

        $user->status = $user->status === 'active' ? 'inactive' : 'active';
        $user->save();

        return back()->with('success', 'User status updated.');
    }
}
