<?php

namespace App\Http\Controllers;

use App\Services\AndroidReleaseService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AndroidDownloadController extends Controller
{
    private const APK_MIME_TYPE = 'application/vnd.android.package-archive';

    public function show(): Response
    {
        $filename = AndroidReleaseService::currentFilename();

        if ($filename) {
            return Storage::disk(AndroidReleaseService::DISK)->download($filename, 'TowMate.apk', [
                'Content-Type' => self::APK_MIME_TYPE,
            ]);
        }

        $legacyPath = AndroidReleaseService::legacyPath();

        if (file_exists($legacyPath)) {
            return response()->download($legacyPath, 'TowMate.apk', [
                'Content-Type' => self::APK_MIME_TYPE,
            ]);
        }

        abort(404);
    }
}
