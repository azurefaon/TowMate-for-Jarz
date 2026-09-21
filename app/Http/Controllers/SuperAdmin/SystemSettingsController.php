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
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

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
        $androidAppActive = AndroidReleaseService::isActive();
        $appDownloadUrl = AndroidReleaseService::APP_DOWNLOAD_URL;

        return view('superadmin.settings.index', compact(
            'settings', 'landing', 'teamLeaderLimit', 'teamLeaderCount',
            'mobileAnnouncements', 'mobileServices', 'mobileHowItWorksSteps', 'mobileCoverageAreas',
            'androidApk', 'androidAppActive', 'appDownloadUrl'
        ));
    }

    public function update(Request $request)
    {
        $request->validate([
            'settings.discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settings.max_dispatcher_discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settings.max_additional_charge' => ['nullable', 'numeric', 'min:0'],
            'settings.vat_rate_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $settings = $request->input('settings', []);

        if (array_key_exists('price_adjustment_form', $settings)) {
            $settings['dispatcher_discount_enabled'] = $request->boolean('settings.dispatcher_discount_enabled') ? '1' : '0';
            $settings['dispatcher_discount_require_reason'] = $request->boolean('settings.dispatcher_discount_require_reason') ? '1' : '0';
        }

        if (array_key_exists('additional_charge_form', $settings)) {
            $settings['additional_charge_require_reason'] = $request->boolean('settings.additional_charge_require_reason') ? '1' : '0';
        }

        unset($settings['price_adjustment_form'], $settings['additional_charge_form']);

        foreach (['company_logo', 'secondary_logo', 'signature_image'] as $fileKey) {
            if ($request->hasFile($fileKey)) {
                $settings[$fileKey] = $request->file($fileKey)->store('settings', 'public');
            }
        }

        if (array_key_exists('vat_rate_percentage', $settings) && filled($settings['vat_rate_percentage'])) {
            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'vat_rate_updated',
                'entity_type' => 'SystemSetting',
                'reference' => 'vat_rate_percentage',
                'description' => 'VAT rate changed to ' . $settings['vat_rate_percentage'] . '%.',
            ]);
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

    public function toggleAppStatus(Request $request)
    {
        $wasActive = AndroidReleaseService::isActive();

        SystemSetting::setValue('android_app_active', $wasActive ? '0' : '1');

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'android_app_status_toggled',
            'entity_type' => 'SystemSetting',
            'reference' => $wasActive ? 'inactive' : 'active',
        ]);

        return back()->with('apk_success', $wasActive
            ? 'Android app is now inactive. The public download is disabled.'
            : 'Android app is now active. The public download is enabled.');
    }

    public function mobileAppQrCode()
    {
        try {
            $result = (new Builder())->build(
                writer: new PngWriter(),
                data: AndroidReleaseService::APP_DOWNLOAD_URL,
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: 320,
                margin: 12,
            );
        } catch (Throwable $e) {
            Log::error('Mobile app QR code generation failed.', ['exception' => $e]);

            return response('Failed to generate the QR code. Please try again.', 500);
        }

        return response($result->getString(), 200, [
            'Content-Type' => $result->getMimeType(),
            'Cache-Control' => 'no-store',
        ]);
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
