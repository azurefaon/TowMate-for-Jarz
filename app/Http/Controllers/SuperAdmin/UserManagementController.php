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
use Illuminate\Validation\Rule;

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

    public function index(Request $request)
    {
        $users = $this->applyFilters($this->baseUserQuery(), $request)
            ->latest()
            ->paginate(10);

        $roles = Role::where('name', '!=', 'Super Admin')->get();
        $stats = $this->getUserStats();

        return view('superadmin.users.index', compact('users', 'roles', 'stats'));
    }

    public function archived(Request $request)
    {
        $archivedUsers = $this->applyFilters($this->baseUserQuery(true), $request)
            ->latest('archived_at')
            ->paginate(10);

        $roles = Role::where('name', '!=', 'Super Admin')->get();
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
        $request->merge([
            'email' => strtolower($request->email),
        ]);

        if ($user->role->name === 'Super Admin') {
            abort(403, 'Cannot modify Super Admin.');
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email:rfc,dns',
                'max:150',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'role_id' => 'required|exists:roles,id',
            'status' => 'required|in:active,inactive',
        ], [
            'email.email' => 'Please enter a valid email address (example: name@gmail.com).',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $user->update($validator->validated());

        return response()->json([
            'success' => true,
        ]);
    }

    public function create()
    {
        $roles = Role::where('name', '!=', 'Super Admin')->get();

        return view('superadmin.users.create', compact('roles'));
    }

    public function store(Request $request)
    {
        $request->merge([
            'email' => strtolower($request->email),
        ]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email:rfc,dns',
                'max:150',
                'unique:users,email',
            ],
            'password' => 'required|min:8|confirmed',
            'role_id' => 'required|exists:roles,id',
            'status' => 'required|in:active,inactive',
        ], [
            'email.required' => 'Email is required.',
            'email.email' => 'Please enter a valid email address (example: name@gmail.com).',
            'email.unique' => 'This email is already registered.',
        ]);

        $user = User::create([
            'name' => $validated['name'],
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
