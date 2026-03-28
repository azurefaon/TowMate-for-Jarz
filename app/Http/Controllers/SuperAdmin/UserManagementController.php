<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Models\TruckType;

class UserManagementController extends Controller
{

    public function edit($id)
    {
        $user = User::findOrFail($id);
        $roles = Role::all();

        return view('superadmin.users.edit', compact('user', 'roles'));
    }

    public function index(Request $request)
    {
        $query = User::with('role')
            ->whereHas('role', function ($q) {
                $q->where('name', '!=', 'Super Admin');
            });

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->role) {
            $query->where('role_id', $request->role);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $users = $query->paginate(7);
        $roles = Role::where('name', '!=', 'Super Admin')->get();

        return view('superadmin.users.index', compact('users', 'roles'));
    }

    public function update(Request $request, User $user)
    {
        $request->merge([
            'email' => strtolower($request->email),
        ]);

        if ($user->role->name === 'Super Admin') {
            abort(403, 'Cannot modify Super Admin.');
        }

        $validator = \Validator::make($request->all(), [
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
                'errors' => $validator->errors()
            ], 422);
        }

        $user->update($validator->validated());

        return response()->json([
            'success' => true
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

        // get role name
        $role = Role::find($validated['role_id']);

        /*
        AUDIT LOG FOR DASHBOARD ACTIVITY
        */

        AuditLog::create([
            'user_id' => auth()->id(),
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

    public function toggleStatus($id)
    {
        $user = User::findOrFail($id);

        if ($user->id == auth()->id()) {
            return back()->with('error', 'Cannot deactivate yourself.');
        }

        $user->status = $user->status == 'active' ? 'inactive' : 'active';
        $user->save();

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'Toggled status for: ' . $user->name,
            'entity_type' => 'User',
            'entity_id' => $user->id
        ]);

        return back();
    }

    public function toggle(User $user)
    {
        $user->status = $user->status === 'active'
            ? 'inactive'
            : 'active';

        $user->save();

        return back();
    }
}
