<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MobileAnnouncement;
use App\Models\MobileCoverageArea;
use App\Models\MobileHowItWorksStep;
use App\Models\MobileService;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;

/**
 * Public, read-only informational content for the Customer Flutter app
 * (Home announcement, About, How It Works, Support, Coverage Areas,
 * Services). Never touches booking/quotation/dispatch/payment logic or
 * pricing. Must never fail in a way that blocks any other customer
 * feature — this endpoint is purely additive/informational.
 */
class CustomerContentController extends Controller
{
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
            ],

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
                ->get(['title', 'description', 'category', 'availability_note'])
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
