<?php

namespace App\Http\Controllers;

use App\Services\AndroidReleaseService;
use Illuminate\View\View;

class PublicSiteController extends Controller
{
    public function index(): View
    {
        $androidActive = AndroidReleaseService::isActive();
        $apkExists = AndroidReleaseService::currentExists();
        $apkUrl = ($apkExists && $androidActive) ? route('download.android') : null;
        $apkMetadata = $apkExists ? AndroidReleaseService::metadata() : [];
        $apkSizeMb = $apkMetadata['size_mb'] ?? null;
        $apkVersionName = $apkMetadata['version_name'] ?? null;

        return view('public.landing', compact('androidActive', 'apkExists', 'apkUrl', 'apkSizeMb', 'apkVersionName'));
    }
}
