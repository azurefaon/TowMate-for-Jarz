<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\AndroidReleaseService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

class SystemSettingsController extends Controller
{
    public function index()
    {
        $settings = SystemSetting::pluck('value', 'key');

        $apkExists = AndroidReleaseService::currentExists();
        $apkMetadata = AndroidReleaseService::metadata();
        $apkSizeMb = $apkMetadata['size_mb'];
        $apkUpdatedAt = $apkMetadata['uploaded_at'] ? Carbon::parse($apkMetadata['uploaded_at']) : null;

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

        $error = AndroidReleaseService::storeUpload($request->file('apk_file'));

        if ($error) {
            return back()->withErrors(['apk_file' => $error]);
        }

        return back()->with('apk_success', 'APK uploaded successfully. Download link is now active.');
    }
}
