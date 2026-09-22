<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MobileAnnouncement;
use App\Models\MobileCoverageArea;
use App\Models\MobileHowItWorksStep;
use App\Models\MobileService;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerContentController extends Controller
{
    private function imageUrl(?string $path): ?string
    {
        return mobile_content_url($path);
    }

    public function media(string $filename): StreamedResponse
    {
        if (! Storage::disk('mobile_content')->exists($filename)) {
            abort(404);
        }

        return Storage::disk('mobile_content')->response($filename);
    }

    public function index(): JsonResponse
    {
        $announcement = MobileAnnouncement::current();

        return response()->json([
            'announcement' => $announcement ? [
                'title' => $announcement->title,
                'message' => $announcement->message,
                'start_at' => optional($announcement->start_at)->toIso8601String(),
                'end_at' => optional($announcement->end_at)->toIso8601String(),
            ] : null,

            'support' => [
                'phone' => SystemSetting::getValue('mobile_support_phone'),
                'email' => SystemSetting::getValue('mobile_support_email'),
                'location' => SystemSetting::getValue('mobile_support_location'),
                'hours' => SystemSetting::getValue('mobile_support_hours'),
            ],

            'about' => [
                'text' => SystemSetting::getValue('mobile_about_text'),
                'image_url' => $this->imageUrl(SystemSetting::getValue('mobile_about_image')),
            ],

            'hero_image_url' => $this->imageUrl(SystemSetting::getValue('mobile_hero_image')),

            'services_image_url' => $this->imageUrl(SystemSetting::getValue('mobile_services_image')),

            'emergency_image_url' => $this->imageUrl(SystemSetting::getValue('mobile_emergency_image')),

            'how_it_works' => MobileHowItWorksStep::query()
                ->where('is_active', true)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get(['step_title', 'step_description'])
                ->map(fn ($step) => [
                    'title' => $step->step_title,
                    'description' => $step->step_description,
                ])
                ->values(),

            'services' => MobileService::query()
                ->where('is_active', true)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get(['title', 'description', 'image_path', 'category', 'availability_note'])
                ->map(fn ($service) => [
                    'title' => $service->title,
                    'description' => $service->description,
                    'image_url' => $this->imageUrl($service->image_path),
                    'category' => $service->category,
                    'availability_note' => $service->availability_note,
                ])
                ->values(),

            'coverage_areas' => MobileCoverageArea::query()
                ->where('is_active', true)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get(['name'])
                ->values(),
        ]);
    }
}
