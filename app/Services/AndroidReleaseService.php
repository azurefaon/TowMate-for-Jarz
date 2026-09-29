<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AndroidReleaseService
{
    public const DISK = 'apk_releases';

    public const APP_DOWNLOAD_URL = 'https://www.jarztowing.com/app';

    public static function legacyPath(): string
    {
        return config('filesystems.legacy_apk_path');
    }

    public static function hasApkSignature(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if (! $handle) {
            return false;
        }

        $signature = fread($handle, 4);
        fclose($handle);

        return $signature === "PK\x03\x04" || $signature === "PK\x05\x06";
    }

    public static function storeUpload(UploadedFile $file, ?string $versionName = null, ?string $releaseNotes = null): ?string
    {
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'apk') {
            return 'The file must have a .apk extension.';
        }

        if (! self::hasApkSignature($file->getRealPath())) {
            return 'The uploaded file is not a valid APK package.';
        }

        $storedName = Str::random(40) . '.apk';
        $stored = $file->storeAs('', $storedName, self::DISK);

        if (! $stored || ! Storage::disk(self::DISK)->exists($storedName)) {
            return 'The upload could not be saved. Please try again.';
        }

        if (Storage::disk(self::DISK)->size($storedName) !== $file->getSize()) {
            Storage::disk(self::DISK)->delete($storedName);

            return 'The upload was incomplete and has been discarded. The previous build is still active. Please try again.';
        }

        $previousFilename = SystemSetting::getValue('android_apk_filename');

        SystemSetting::setValue('android_apk_filename', $storedName);
        SystemSetting::setValue('android_apk_version_name', $versionName);
        SystemSetting::setValue('android_apk_release_notes', $releaseNotes);
        SystemSetting::setValue('android_apk_uploaded_at', now()->toIso8601String());

        if ($previousFilename && $previousFilename !== $storedName) {
            Storage::disk(self::DISK)->delete($previousFilename);
        }

        return null;
    }

    public static function isActive(): bool
    {
        return SystemSetting::getValue('android_app_active', '1') !== '0';
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
