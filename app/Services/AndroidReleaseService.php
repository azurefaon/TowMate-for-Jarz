<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Storage;

class AndroidReleaseService
{
    public const DISK = 'apk_releases';

    public static function legacyPath(): string
    {
        return config('filesystems.legacy_apk_path');
    }

    public static function currentFilename(): ?string
    {
        $filename = SystemSetting::getValue('android_apk_filename');

        if ($filename && Storage::disk(self::DISK)->exists($filename)) {
            return $filename;
        }

        return null;
    }

    public static function currentExists(): bool
    {
        return self::currentFilename() !== null || file_exists(self::legacyPath());
    }

    public static function metadata(): array
    {
        $filename = self::currentFilename();

        if ($filename) {
            return [
                'source' => 'upload',
                'version_name' => SystemSetting::getValue('android_apk_version_name'),
                'release_notes' => SystemSetting::getValue('android_apk_release_notes'),
                'size_mb' => round(Storage::disk(self::DISK)->size($filename) / 1048576, 1),
                'uploaded_at' => SystemSetting::getValue('android_apk_uploaded_at'),
            ];
        }

        $legacyPath = self::legacyPath();

        if (file_exists($legacyPath)) {
            return [
                'source' => 'legacy',
                'version_name' => null,
                'release_notes' => null,
                'size_mb' => round(filesize($legacyPath) / 1048576, 1),
                'uploaded_at' => date('c', filemtime($legacyPath)),
            ];
        }

        return [
            'source' => null,
            'version_name' => null,
            'release_notes' => null,
            'size_mb' => null,
            'uploaded_at' => null,
        ];
    }
}
