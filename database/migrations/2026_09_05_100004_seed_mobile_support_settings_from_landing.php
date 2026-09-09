<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time copy of the old landing-page contact fields into the new
     * mobile_support_* SystemSetting keys, which become the Customer Mobile
     * CMS's sole source of truth going forward. landing_settings itself is
     * left untouched and unreferenced by the mobile app after this point.
     */
    public function up(): void
    {
        if (! Schema::hasTable('landing_settings') || ! Schema::hasTable('system_settings')) {
            return;
        }

        $landing = DB::table('landing_settings')->first();
        $now = now();

        $map = [
            'mobile_support_phone'    => $landing->contact_phone ?? null,
            'mobile_support_email'    => $landing->contact_email ?? null,
            'mobile_support_location' => $landing->contact_location ?? null,
        ];

        $wrote = false;

        foreach ($map as $key => $value) {
            if (blank($value)) {
                continue;
            }

            DB::table('system_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => $now, 'created_at' => $now]
            );
            $wrote = true;
        }

        // SystemSetting::allCached() caches the whole key/value table forever
        // (Cache::rememberForever). Raw DB::table() writes here bypass that
        // cache entirely, so on any environment where the cache was already
        // warm (e.g. from an earlier settings-page load), the new keys would
        // silently never appear via SystemSetting::getValue() until this is
        // invalidated.
        if ($wrote) {
            Cache::forget('towmate_settings');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        DB::table('system_settings')->whereIn('key', [
            'mobile_support_phone',
            'mobile_support_email',
            'mobile_support_location',
        ])->delete();

        Cache::forget('towmate_settings');
    }
};
