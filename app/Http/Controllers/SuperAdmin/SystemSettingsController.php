<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AuditLog;
use App\Models\MobileAnnouncement;
use App\Models\MobileCoverageArea;
use App\Models\MobileHowItWorksStep;
use App\Models\MobileService;
use App\Models\SystemSetting;
use App\Models\LandingSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\AndroidReleaseService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        $androidApk = AndroidReleaseService::metadata();

        return view('superadmin.settings.index', compact(
            'settings', 'landing', 'teamLeaderLimit', 'teamLeaderCount',
            'mobileAnnouncements', 'mobileServices', 'mobileHowItWorksSteps', 'mobileCoverageAreas',
            'androidApk'
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
        $validated = $request->validate([
            'apk_file' => ['required', 'file', 'max:102400'],
            'version_name' => ['nullable', 'string', 'max:50'],
            'release_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $file = $request->file('apk_file');

        if (strtolower((string) $file->getClientOriginalExtension()) !== 'apk') {
            return back()->withErrors(['apk_file' => 'The file must have a .apk extension.'])->withInput();
        }

        if (! $this->hasApkSignature($file->getRealPath())) {
            return back()->withErrors(['apk_file' => 'The uploaded file is not a valid APK package.'])->withInput();
        }

        $storedName = Str::random(40) . '.apk';
        $stored = $file->storeAs('', $storedName, AndroidReleaseService::DISK);

        if (! $stored || ! Storage::disk(AndroidReleaseService::DISK)->exists($storedName)) {
            return back()->withErrors(['apk_file' => 'The upload could not be saved. Please try again.'])->withInput();
        }

        if (Storage::disk(AndroidReleaseService::DISK)->size($storedName) !== $file->getSize()) {
            Storage::disk(AndroidReleaseService::DISK)->delete($storedName);

            return back()->withErrors(['apk_file' => 'The upload was incomplete and has been discarded. The previous build is still active. Please try again.'])->withInput();
        }

        $previousFilename = SystemSetting::getValue('android_apk_filename');

        SystemSetting::setValue('android_apk_filename', $storedName);
        SystemSetting::setValue('android_apk_version_name', $validated['version_name'] ?? null);
        SystemSetting::setValue('android_apk_release_notes', $validated['release_notes'] ?? null);
        SystemSetting::setValue('android_apk_uploaded_at', now()->toIso8601String());

        if ($previousFilename && $previousFilename !== $storedName) {
            Storage::disk(AndroidReleaseService::DISK)->delete($previousFilename);
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'android_apk_uploaded',
            'entity_type' => 'SystemSetting',
            'reference' => $validated['version_name'] ?? 'android_apk',
        ]);

        return back()->with('apk_success', 'Android app updated. The download link now serves this build.');
    }

    private function hasApkSignature(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if (! $handle) {
            return false;
        }

        $signature = fread($handle, 4);
        fclose($handle);

        return $signature === "PK\x03\x04" || $signature === "PK\x05\x06";
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
