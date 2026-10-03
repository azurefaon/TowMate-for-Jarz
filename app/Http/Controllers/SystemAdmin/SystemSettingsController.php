<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class SystemSettingsController extends Controller
{
    public function index()
    {
        $settings = SystemSetting::pluck('value', 'key');

        return view('system-admin.settings.index', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'deleted_retention_days' => ['required', 'integer', 'min:1', 'max:365'],
            'customer_inactivity_lock_days' => ['required', 'integer', 'min:1', 'max:365'],
            'max_team_leaders' => ['required', 'integer', 'min:1', 'max:500'],
        ]);

        foreach ($validated as $key => $value) {
            SystemSetting::setValue($key, $value);
        }

        return back()->with('success', 'System settings updated successfully.');
    }
}
