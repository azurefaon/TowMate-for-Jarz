<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SystemSetting;

class SystemSettingsController extends Controller
{
    public function index()
    {

        $settings = SystemSetting::pluck('setting_value', 'setting_key');

        return view('superadmin.settings.index', compact('settings'));
    }

    public function update(Request $request)
    {

        $settings = $request->input('settings', []);

        foreach ($settings as $key => $value) {
            SystemSetting::setValue($key, $value);
        }

        return back()->with('success', 'Settings saved successfully');
    }
}
