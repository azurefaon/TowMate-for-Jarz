<?php

namespace App\Http\Controllers;

use App\Services\AndroidReleaseService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AndroidDownloadController extends Controller
{
    private const APK_MIME_TYPE = 'application/vnd.android.package-archive';

    private const UNAVAILABLE_MESSAGE = 'TowMate is temporarily unavailable. Please check again later.';

    public function show(): Response
    {
        if (! AndroidReleaseService::isActive()) {
            abort(503, self::UNAVAILABLE_MESSAGE);
        }

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

    public function landing()
    {
        $apkExists = AndroidReleaseService::currentExists();
        $androidActive = AndroidReleaseService::isActive();
        $apkUrl = ($apkExists && $androidActive) ? route('download.android') : null;
        $apkSizeMb = $apkExists ? AndroidReleaseService::metadata()['size_mb'] : null;

        return view('app-download', compact('apkExists', 'androidActive', 'apkUrl', 'apkSizeMb'));
    }
}
