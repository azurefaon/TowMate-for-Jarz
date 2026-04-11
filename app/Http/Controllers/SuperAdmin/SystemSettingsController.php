<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SystemSetting;
use App\Models\LandingSetting;

class SystemSettingsController extends Controller
{
    public function index()
    {

        // $settings = SystemSetting::pluck('setting_value', 'setting_key');
        $landing = LandingSetting::first();
        $settings = SystemSetting::pluck('value', 'key');

        return view('superadmin.settings.index', compact('settings', 'landing'));
    }

    public function update(Request $request)
    {
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

        return back()->with('success', 'Settings saved successfully');
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

        return back()->with('success', 'Landing page updated!');
    }
}
