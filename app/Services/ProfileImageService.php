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

    public function storeBinary(User $user, string $contents, string $extension): string
    {
        $path = $user->id . '/' . Str::random(40) . '.' . $extension;

        Storage::disk(self::DISK)->put($path, $contents);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
