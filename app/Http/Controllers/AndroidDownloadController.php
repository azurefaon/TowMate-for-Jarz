<?php

namespace App\Http\Controllers;

use App\Services\AndroidReleaseService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AndroidDownloadController extends Controller
{
    public function show(): Response
    {
        $filename = AndroidReleaseService::currentFilename();

        if ($filename) {
            return Storage::disk(AndroidReleaseService::DISK)->download($filename, 'TowMate.apk');
        }

        $legacyPath = AndroidReleaseService::legacyPath();

        if (file_exists($legacyPath)) {
            return response()->download($legacyPath, 'TowMate.apk');
        }

        abort(404);
    }
}
