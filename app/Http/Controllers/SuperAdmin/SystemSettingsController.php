<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MobileAnnouncement;
use App\Models\MobileCoverageArea;
use App\Models\MobileHowItWorksStep;
use App\Models\MobileService;
use App\Models\SystemSetting;
use App\Models\LandingSetting;
use App\Models\Role;
use App\Models\User;

class SystemSettingsController extends Controller
{
    public function index()
    {
        $landing = LandingSetting::first();
        $settings = SystemSetting::pluck('value', 'key');
        $teamLeaderRole = Role::query()->where('name', 'Team Leader')->first();
        $teamLeaderLimit = max((int) ($settings['max_team_leaders'] ?? 10), 1);
        $teamLeaderCount = $teamLeaderRole
            ? User::query()->where('role_id', $teamLeaderRole->id)->whereNull('archived_at')->count()
            : 0;

        $mobileAnnouncements = MobileAnnouncement::latest('id')->get();
        $mobileServices = MobileService::orderBy('display_order')->orderBy('id')->get();
        $mobileHowItWorksSteps = MobileHowItWorksStep::orderBy('display_order')->orderBy('id')->get();
        $mobileCoverageAreas = MobileCoverageArea::orderBy('display_order')->orderBy('id')->get();

        return view('superadmin.settings.index', compact(
            'settings', 'landing', 'teamLeaderLimit', 'teamLeaderCount',
            'mobileAnnouncements', 'mobileServices', 'mobileHowItWorksSteps', 'mobileCoverageAreas'
        ));
    }

    public function update(Request $request)
    {
        $request->validate([
            'settings.max_team_leaders' => ['nullable', 'integer', 'min:1', 'max:500'],
            'settings.deleted_retention_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'settings.customer_inactivity_lock_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $settings = $request->input('settings', []);

        foreach (['company_logo', 'secondary_logo', 'signature_image'] as $fileKey) {
            if ($request->hasFile($fileKey)) {
                $settings[$fileKey] = $request->file($fileKey)->store('settings', 'public');
            }
        }

        foreach ($settings as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            SystemSetting::setValue($key, $value);
        }

        return back()->with('success', 'Business settings updated successfully.');
    }

    public function uploadApk(Request $request)
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

    public function updateLanding(Request $request)
    {

        $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'contact_phone' => ['required', 'regex:/^09\d{9}$/'],
            'contact_email' => ['required', 'email', 'regex:/^[a-zA-Z0-9._%+-]+@gmail\.com$/'],
            'company_logo' => ['nullable', 'image', 'max:2048'],
            'secondary_logo' => ['nullable', 'image', 'max:2048'],
            'signature_image' => ['nullable', 'image', 'max:2048'],
        ]);

        $landing = LandingSetting::first() ?? new LandingSetting();

        if ($request->hasFile('hero_image')) {
            $landing->hero_image = $request->file('hero_image')->store('landing', 'public');
        }

        if ($request->hasFile('about_image')) {
            $landing->about_image = $request->file('about_image')->store('landing', 'public');
        }

        if ($request->hasFile('portfolio_main')) {
            $landing->portfolio_main = $request->file('portfolio_main')->store('landing', 'public');
        }

        if ($request->hasFile('portfolio_1')) {
            $landing->portfolio_1 = $request->file('portfolio_1')->store('landing', 'public');
        }

        if ($request->hasFile('portfolio_2')) {
            $landing->portfolio_2 = $request->file('portfolio_2')->store('landing', 'public');
        }

        if ($request->hasFile('portfolio_3')) {
            $landing->portfolio_3 = $request->file('portfolio_3')->store('landing', 'public');
        }

        $landing->contact_phone = $request->contact_phone;
        $landing->contact_email = $request->contact_email;
        $landing->contact_location = $request->contact_location;

        $landing->save();

        SystemSetting::setValue('company_name', $request->company_name);
        SystemSetting::setValue('company_phone', $request->contact_phone);
        SystemSetting::setValue('company_email', $request->contact_email);
        SystemSetting::setValue('company_address', $request->contact_location);

        foreach (['company_logo', 'secondary_logo', 'signature_image'] as $fileKey) {
            if ($request->hasFile($fileKey)) {
                SystemSetting::setValue($fileKey, $request->file($fileKey)->store('settings', 'public'));
            }
        }

        return back()->with('success');
    }
}
