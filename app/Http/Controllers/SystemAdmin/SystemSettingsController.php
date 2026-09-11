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

        $apkPath = public_path('downloads/towmate.apk');
        $apkExists = file_exists($apkPath);
        $apkSizeMb = $apkExists ? round(filesize($apkPath) / 1048576, 1) : null;
        $apkUpdatedAt = $apkExists ? \Illuminate\Support\Carbon::createFromTimestamp(filemtime($apkPath)) : null;

        return view('system-admin.settings.index', compact('settings', 'apkExists', 'apkSizeMb', 'apkUpdatedAt'));
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

    public function uploadApk(Request $request): RedirectResponse
    {
        $request->validate([
            'apk_file' => ['required', 'file', 'max:102400'],
        ]);

        if ($request->file('apk_file')->getClientOriginalExtension() !== 'apk') {
            return back()->withErrors(['apk_file' => 'The file must be a .apk file.']);
        }

        $dest = public_path('downloads');
        if (! is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $request->file('apk_file')->move($dest, 'towmate.apk');

        return back()->with('apk_success', 'APK uploaded successfully. Download link is now active.');
    }
}
