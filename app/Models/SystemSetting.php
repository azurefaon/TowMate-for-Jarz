<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected static $cacheKey = 'towmate_settings';

    public static function allCached()
    {
        return Cache::rememberForever(self::$cacheKey, function () {
            return self::all()->pluck('value', 'key');
        });
    }

    public static function getValue($key, $default = null)
    {
        $settings = self::allCached();
        return $settings[$key] ?? $default;
    }

    public static function setValue($key, $value)
    {
        $setting = self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );

        Cache::forget(self::$cacheKey);

        return $setting;
    }

    public static function currentTermsVersion(): string
    {
        return (string) self::getValue('terms_of_use_version', config('towmate.current_terms_version'));
    }

    public static function currentPrivacyVersion(): string
    {
        return (string) self::getValue('privacy_policy_version', config('towmate.current_privacy_version'));
    }
}
