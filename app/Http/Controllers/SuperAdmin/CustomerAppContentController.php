<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MobileAnnouncement;
use App\Models\MobileCoverageArea;
use App\Models\MobileHowItWorksStep;
use App\Models\MobileService;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Owner-only content management for the Customer Flutter app's
 * informational screens (Home announcement, Services, About, How It
 * Works, Coverage Areas). Lives under System Settings -> Customer App
 * Content in the UI; never touches booking/quotation/dispatch/payment
 * logic or pricing.
 */
class CustomerAppContentController extends Controller
{
    private function clean(?string $value): ?string
    {
        return $value === null ? null : trim(strip_tags($value));
    }

    /**
     * Swaps display_order between $model and its immediate neighbor (by
     * display_order, id) in $direction. No-ops silently at either end of
     * the list. Reuses the model's own class for the sibling query, so this
     * is generic across MobileService/MobileHowItWorksStep/MobileCoverageArea.
     */
    private function moveInOrder($model, string $direction): void
    {
        $query = $model::query();

        if ($direction === 'up') {
            $sibling = (clone $query)
                ->where(function ($q) use ($model) {
                    $q->where('display_order', '<', $model->display_order)
                        ->orWhere(function ($q2) use ($model) {
                            $q2->where('display_order', $model->display_order)->where('id', '<', $model->id);
                        });
                })
                ->orderByDesc('display_order')->orderByDesc('id')
                ->first();
        } else {
            $sibling = (clone $query)
                ->where(function ($q) use ($model) {
                    $q->where('display_order', '>', $model->display_order)
                        ->orWhere(function ($q2) use ($model) {
                            $q2->where('display_order', $model->display_order)->where('id', '>', $model->id);
                        });
                })
                ->orderBy('display_order')->orderBy('id')
                ->first();
        }

        if (! $sibling) {
            return; // already at that end of the list
        }

        $modelOrder = $model->display_order;
        $siblingOrder = $sibling->display_order;

        $model->update(['display_order' => $siblingOrder]);
        $sibling->update(['display_order' => $modelOrder]);
    }

    // ── Announcements ───────────────────────────────────────────────────

    public function announcementStore(Request $request): RedirectResponse
    {
        $validated = $this->validateAnnouncement($request);

        $announcement = MobileAnnouncement::create([
            'title' => $this->clean($validated['title']),
            'message' => $this->clean($validated['message']),
            'is_active' => $request->boolean('is_active', true),
            'start_at' => $validated['start_at'] ?? null,
            'end_at' => $validated['end_at'] ?? null,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'mobile_announcement_created',
            'entity_type' => 'MobileAnnouncement',
            'entity_id' => $announcement->id,
            'reference' => $announcement->title,
        ]);

        return back()->with('success', 'Announcement created.');
    }

    public function announcementUpdate(Request $request, MobileAnnouncement $announcement): RedirectResponse
    {
        $validated = $this->validateAnnouncement($request);

        $announcement->update([
            'title' => $this->clean($validated['title']),
            'message' => $this->clean($validated['message']),
            'start_at' => $validated['start_at'] ?? null,
            'end_at' => $validated['end_at'] ?? null,
            'updated_by' => Auth::id(),
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'mobile_announcement_updated',
            'entity_type' => 'MobileAnnouncement',
            'entity_id' => $announcement->id,
            'reference' => $announcement->title,
        ]);

        return back()->with('success', 'Announcement updated.');
    }

    public function announcementToggle(MobileAnnouncement $announcement): RedirectResponse
    {
        $announcement->update([
            'is_active' => ! $announcement->is_active,
            'updated_by' => Auth::id(),
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'mobile_announcement_status_changed',
            'entity_type' => 'MobileAnnouncement',
            'entity_id' => $announcement->id,
            'reference' => $announcement->title,
            'description' => $announcement->is_active ? 'Activated' : 'Deactivated',
        ]);

        return back()->with('success', 'Announcement status updated.');
    }

    private function validateAnnouncement(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:2000'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', function ($attribute, $value, $fail) use ($request) {
                if ($value && $request->filled('start_at') && strtotime($value) <= strtotime($request->input('start_at'))) {
                    $fail('End date must be after the start date.');
                }
            }],
        ]);
    }

    // ── Services ─────────────────────────────────────────────────────────

    public function serviceStore(Request $request): RedirectResponse
    {
        $validated = $this->validateService($request);

        $service = MobileService::create([
            'title' => $this->clean($validated['title']),
            'description' => $this->clean($validated['description']),
            'category' => $this->clean($validated['category'] ?? null),
            'availability_note' => $this->clean($validated['availability_note'] ?? null),
            'display_order' => $validated['display_order'] ?? ((int) MobileService::max('display_order') + 1),
            'is_active' => $request->boolean('is_active', true),
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'mobile_service_created',
            'entity_type' => 'MobileService',
            'entity_id' => $service->id,
            'reference' => $service->title,
        ]);

        return back()->with('success', 'Service added.');
    }

    public function serviceMove(Request $request, MobileService $service): RedirectResponse
    {
        $direction = $request->input('direction') === 'up' ? 'up' : 'down';
        $this->moveInOrder($service, $direction);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'mobile_service_reordered',
            'entity_type' => 'MobileService',
            'entity_id' => $service->id,
            'reference' => $service->title,
        ]);

        return back()->with('success', 'Service order updated.');
    }

    public function serviceUpdate(Request $request, MobileService $service): RedirectResponse
    {
        $validated = $this->validateService($request);

        $orderOnly = (int) ($validated['display_order'] ?? $service->display_order) !== (int) $service->display_order
            && $this->clean($validated['title']) === $service->title
            && $this->clean($validated['description']) === $service->description
            && $this->clean($validated['category'] ?? null) === $service->category
            && $this->clean($validated['availability_note'] ?? null) === $service->availability_note;

        $service->update([
            'title' => $this->clean($validated['title']),
            'description' => $this->clean($validated['description']),
            'category' => $this->clean($validated['category'] ?? null),
            'availability_note' => $this->clean($validated['availability_note'] ?? null),
            'display_order' => $validated['display_order'] ?? $service->display_order,
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $orderOnly ? 'mobile_service_reordered' : 'mobile_service_updated',
            'entity_type' => 'MobileService',
            'entity_id' => $service->id,
            'reference' => $service->title,
        ]);

        return back()->with('success', 'Service updated.');
    }

    public function serviceToggle(MobileService $service): RedirectResponse
    {
        $service->update(['is_active' => ! $service->is_active]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'mobile_service_status_changed',
            'entity_type' => 'MobileService',
            'entity_id' => $service->id,
            'reference' => $service->title,
            'description' => $service->is_active ? 'Activated' : 'Deactivated',
        ]);

        return back()->with('success', 'Service status updated.');
    }

    private function validateService(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:100'],
            'availability_note' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    // ── How It Works ─────────────────────────────────────────────────────

    public function howItWorksStore(Request $request): RedirectResponse
    {
        $validated = $this->validateHowItWorks($request);

        $step = MobileHowItWorksStep::create([
            'step_title' => $this->clean($validated['step_title']),
            'step_description' => $this->clean($validated['step_description']),
            'display_order' => $validated['display_order'] ?? ((int) MobileHowItWorksStep::max('display_order') + 1),
            'is_active' => $request->boolean('is_active', true),
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'mobile_how_it_works_step_created',
            'entity_type' => 'MobileHowItWorksStep',
            'entity_id' => $step->id,
            'reference' => $step->step_title,
        ]);

        return back()->with('success', 'Step added.');
    }

    public function howItWorksMove(Request $request, MobileHowItWorksStep $step): RedirectResponse
    {
        $direction = $request->input('direction') === 'up' ? 'up' : 'down';
        $this->moveInOrder($step, $direction);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'mobile_how_it_works_step_reordered',
            'entity_type' => 'MobileHowItWorksStep',
            'entity_id' => $step->id,
            'reference' => $step->step_title,
        ]);

        return back()->with('success', 'Step order updated.');
    }

    public function howItWorksUpdate(Request $request, MobileHowItWorksStep $step): RedirectResponse
    {
        $validated = $this->validateHowItWorks($request);

        $orderOnly = (int) ($validated['display_order'] ?? $step->display_order) !== (int) $step->display_order
            && $this->clean($validated['step_title']) === $step->step_title
            && $this->clean($validated['step_description']) === $step->step_description;

        $step->update([
            'step_title' => $this->clean($validated['step_title']),
            'step_description' => $this->clean($validated['step_description']),
            'display_order' => $validated['display_order'] ?? $step->display_order,
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $orderOnly ? 'mobile_how_it_works_step_reordered' : 'mobile_how_it_works_step_updated',
            'entity_type' => 'MobileHowItWorksStep',
            'entity_id' => $step->id,
            'reference' => $step->step_title,
        ]);

        return back()->with('success', 'Step updated.');
    }

    public function howItWorksToggle(MobileHowItWorksStep $step): RedirectResponse
    {
        $step->update(['is_active' => ! $step->is_active]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'mobile_how_it_works_step_status_changed',
            'entity_type' => 'MobileHowItWorksStep',
            'entity_id' => $step->id,
            'reference' => $step->step_title,
            'description' => $step->is_active ? 'Activated' : 'Deactivated',
        ]);

        return back()->with('success', 'Step status updated.');
    }

    private function validateHowItWorks(Request $request): array
    {
        return $request->validate([
            'step_title' => ['required', 'string', 'max:150'],
            'step_description' => ['required', 'string', 'max:2000'],
            'display_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    // ── Coverage Areas ───────────────────────────────────────────────────

    public function coverageAreaStore(Request $request): RedirectResponse
    {
        $validated = $this->validateCoverageArea($request);

        $area = MobileCoverageArea::create([
            'name' => $this->clean($validated['name']),
            'display_order' => $validated['display_order'] ?? ((int) MobileCoverageArea::max('display_order') + 1),
            'is_active' => $request->boolean('is_active', true),
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'mobile_coverage_area_created',
            'entity_type' => 'MobileCoverageArea',
            'entity_id' => $area->id,
            'reference' => $area->name,
        ]);

        return back()->with('success', 'Coverage area added.');
    }

    public function coverageAreaMove(Request $request, MobileCoverageArea $coverageArea): RedirectResponse
    {
        $direction = $request->input('direction') === 'up' ? 'up' : 'down';
        $this->moveInOrder($coverageArea, $direction);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'mobile_coverage_area_reordered',
            'entity_type' => 'MobileCoverageArea',
            'entity_id' => $coverageArea->id,
            'reference' => $coverageArea->name,
        ]);

        return back()->with('success', 'Coverage area order updated.');
    }

    public function coverageAreaUpdate(Request $request, MobileCoverageArea $coverageArea): RedirectResponse
    {
        $validated = $this->validateCoverageArea($request);

        $orderOnly = (int) ($validated['display_order'] ?? $coverageArea->display_order) !== (int) $coverageArea->display_order
            && $this->clean($validated['name']) === $coverageArea->name;

        $coverageArea->update([
            'name' => $this->clean($validated['name']),
            'display_order' => $validated['display_order'] ?? $coverageArea->display_order,
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $orderOnly ? 'mobile_coverage_area_reordered' : 'mobile_coverage_area_updated',
            'entity_type' => 'MobileCoverageArea',
            'entity_id' => $coverageArea->id,
            'reference' => $coverageArea->name,
        ]);

        return back()->with('success', 'Coverage area updated.');
    }

    public function coverageAreaToggle(MobileCoverageArea $coverageArea): RedirectResponse
    {
        $coverageArea->update(['is_active' => ! $coverageArea->is_active]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'mobile_coverage_area_status_changed',
            'entity_type' => 'MobileCoverageArea',
            'entity_id' => $coverageArea->id,
            'reference' => $coverageArea->name,
            'description' => $coverageArea->is_active ? 'Activated' : 'Deactivated',
        ]);

        return back()->with('success', 'Coverage area status updated.');
    }

    private function validateCoverageArea(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'display_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    // ── About & Support (singleton SystemSetting keys) ──────────────────

    public function aboutUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mobile_about_text' => ['required', 'string', 'max:2000'],
        ]);

        SystemSetting::setValue('mobile_about_text', $this->clean($validated['mobile_about_text']));

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'mobile_about_updated',
            'entity_type' => 'SystemSetting',
            'reference' => 'mobile_about_text',
        ]);

        return back()->with('success', 'About content updated.');
    }

    public function supportUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mobile_support_phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{5,20}$/'],
            'mobile_support_email' => ['required', 'email:rfc', 'max:150'],
            'mobile_support_location' => ['required', 'string', 'max:255'],
            'mobile_support_hours' => ['required', 'string', 'max:255'],
        ]);

        foreach ($validated as $key => $value) {
            SystemSetting::setValue($key, $this->clean($value));
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'mobile_support_updated',
            'entity_type' => 'SystemSetting',
            'reference' => 'mobile_support_*',
        ]);

        return back()->with('success', 'Support information updated.');
    }
}
