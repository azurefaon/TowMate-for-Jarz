<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
                $q->where('name', '!=', 'Super Admin');
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
            $q->where('name', '!=', 'Super Admin');
        });

        return [
            'total' => (clone $baseQuery)->whereNull('archived_at')->count(),
            'active' => (clone $baseQuery)->whereNull('archived_at')->where('status', 'active')->count(),
            'inactive' => (clone $baseQuery)->whereNull('archived_at')->where('status', 'inactive')->count(),
            'archived' => (clone $baseQuery)->whereNotNull('archived_at')->count(),
        ];
    }

    protected function normalizeUserInput(Request $request): void
    {
        $nameParts = split_full_name($request->input('name'));
        $firstName = trim((string) ($request->input('first_name') ?: $nameParts['first_name']));
        $middleName = trim((string) ($request->input('middle_name') ?: $nameParts['middle_name']));
        $lastName = trim((string) ($request->input('last_name') ?: $nameParts['last_name']));

        $request->merge([
            'first_name' => $firstName !== '' ? $firstName : null,
            'middle_name' => $middleName !== '' ? $middleName : null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'name' => build_full_name($firstName, $middleName, $lastName),
            'email' => strtolower(trim((string) $request->input('email'))),
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

    public function index(Request $request)
    {
        $users = $this->applyFilters($this->baseUserQuery(), $request)
            ->latest()
            ->paginate(10);

        $roles = $this->manageableRoles();
        $stats = $this->getUserStats();

        return view('superadmin.users.index', compact('users', 'roles', 'stats'));
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
        $user = User::findOrFail($id);
        $roles = Role::all();

        return view('superadmin.users.edit', compact('user', 'roles'));
    }

    public function update(Request $request, User $user)
    {
        $this->normalizeUserInput($request);

        if ($user->role->name === 'Super Admin') {
            abort(403, 'Cannot modify Super Admin.');
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => $this->emailRules($user),
            'status' => 'required|in:active,inactive',
            'role_id' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                    if ($value !== null && (int) $value !== (int) $user->role_id) {
                        $fail('Role cannot be changed after user creation.');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $statusChanged = $user->status !== $validated['status'];
        $requiresRelogin = $statusChanged;

        $user->update([
            'name' => build_full_name($validated['first_name'], $validated['middle_name'] ?? null, $validated['last_name']),
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'status' => $validated['status'],
        ]);

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
                : 'User details updated successfully.',
        ]);
    }

    public function create()
    {
        $roles = $this->manageableRoles();

        return view('superadmin.users.create', compact('roles'));
    }

    public function store(Request $request)
    {
        $this->normalizeUserInput($request);

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => $this->emailRules(),
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
            'role_id' => 'required|exists:roles,id',
            'status' => 'required|in:active,inactive',
        ], [
            'email.required' => 'Email is required.',
            'email.unique' => 'This email is already registered.',
            'password.confirmed' => 'Password confirmation does not match.',
        ]);

        $user = User::create([
            'name' => build_full_name($validated['first_name'], $validated['middle_name'] ?? null, $validated['last_name']),
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role_id' => $validated['role_id'],
            'status' => $validated['status'],
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

        $user->update([
            'status' => 'inactive',
            'archived_at' => now(),
        ]);

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
