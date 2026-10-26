<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProfileImageService
{
    private const DISK = 'profile_images';

    public function storeUploadedFile(User $user, UploadedFile $file): string
    {
        return $file->store((string) $user->id, self::DISK);
    }

    public function storeBinary(User $user, string $contents, string $extension): ?string
    {
        $path = $user->id . '/' . Str::random(40) . '.' . $extension;

        // The disk is configured with throw=false, so a failed write returns false.
        if (! Storage::disk(self::DISK)->put($path, $contents)) {
            return null;
        }

        return $path;
    }

    /**
     * Where a stored profile_image value really lives. Mobile/Google photos are on the private
     * profile_images disk; photos uploaded from the System Admin web form before this fix are on the
     * public disk (avatars/...). Returns [disk, path], or null when the file is missing.
     */
    public function locate(?string $path): ?array
    {
        if (blank($path)) {
            return null;
        }

        foreach ([self::DISK, 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return [$disk, $path];
            }
        }

        return null;
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** Delete a stored photo from whichever disk holds it. */
    public function deleteAnywhere(?string $path): void
    {
        if ($found = $this->locate($path)) {
            Storage::disk($found[0])->delete($found[1]);
        }
    }
}
