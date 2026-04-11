<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Customer;
use App\Models\Role;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $customerRole = Role::find(5);

        if (! $customerRole) {
            $customerRole = new Role([
                'name' => 'Customer',
                'description' => 'Customer role',
            ]);
            $customerRole->id = 5;
            $customerRole->save();
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role_id' => $customerRole->id,
            'status' => 'active',
        ]);

        $lastCustomer = Customer::orderBy('id', 'desc')->first();

        if (!$lastCustomer || !$lastCustomer->customer_code) {
            $number = 1;
        } else {
            $lastNumber = (int) str_replace('CUST-', '', $lastCustomer->customer_code);
            $number = $lastNumber + 1;
        }

        $customerCode = 'CUST-' . str_pad($number, 3, '0', STR_PAD_LEFT);

        Customer::create([
            'user_id' => $user->id,
            'customer_code' => $customerCode,
            'full_name' => $user->name,
            'phone' => $request->phone ?? null,
        ]);

        event(new Registered($user));

        if (! Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
            Auth::login($user);
        }

        Auth::setUser($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
