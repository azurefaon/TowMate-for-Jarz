<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ProfileImageService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class UserAvatarController extends Controller
{
    /**
     * Serves a user's profile photo to a signed-in System Admin only (route group: auth + role:6).
     * The stored path is never shown; a missing file is a plain 404 and the page falls back to the
     * default avatar.
     */
    public function show(User $user, ProfileImageService $images): Response
    {
        $found = $images->locate($user->profile_image);

        abort_if($found === null, 404);

        return Storage::disk($found[0])->response($found[1], null, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
