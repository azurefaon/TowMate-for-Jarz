<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use App\Services\QuotationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class QuotationController extends Controller
{
    protected QuotationService $quotationService;

    public function __construct(QuotationService $quotationService)
    {
        $this->quotationService = $quotationService;
    }

    public function show(Request $request, Quotation $quotation)
    {
        if (!$request->hasValidSignature()) {
            abort(403, 'Invalid or expired link');
        }

        $quotation->markAsViewed();
        $quotation->load(['customer', 'truckType']);

        $requestedVersion = (int) $request->query('v', 1);
        $isOutdated = ($quotation->link_version ?? 1) > $requestedVersion;

        // Generate fresh signed URLs only when not outdated
        $expiresAt = $quotation->expires_at ?? now()->addDays(7);
        $remaining = now()->diffInSeconds($expiresAt, false);
        $validFor  = max($remaining, 60);
        $version   = $quotation->link_version ?? 1;

        $signedAcceptUrl    = $isOutdated ? null : URL::temporarySignedRoute('quotation.accept',    now()->addSeconds($validFor), ['quotation' => $quotation->id, 'v' => $version]);
        $signedRejectUrl    = URL::temporarySignedRoute('quotation.reject',    now()->addSeconds($validFor), ['quotation' => $quotation->id, 'v' => $version]);
        $signedNegotiateUrl = URL::temporarySignedRoute('quotation.negotiate', now()->addSeconds($validFor), ['quotation' => $quotation->id, 'v' => $version]);

        return view('customer.quotation-view', [
            'quotation'          => $quotation,
            'signedAcceptUrl'    => $signedAcceptUrl,
            'signedRejectUrl'    => $signedRejectUrl,
            'signedNegotiateUrl' => $signedNegotiateUrl,
            'isOutdated'         => $isOutdated,
        ]);
    }

    public function accept(Request $request, Quotation $quotation)
    {
        if (!$request->hasValidSignature()) {
            abort(403, 'Invalid or expired link');
        }

        $requestedVersion = (int) $request->query('v', 1);
        if (($quotation->link_version ?? 1) > $requestedVersion) {
            return $this->redirectToShow($quotation, 'error', 'This quotation was updated after your link was sent. Please wait for a new quotation link from the dispatcher.');
        }

        if ($quotation->status !== 'sent') {
            return $this->redirectToShow($quotation, 'error', 'This quotation has already been responded to.');
        }

        if ($quotation->isExpired()) {
            return $this->redirectToShow($quotation, 'error', 'This quotation has expired. Please contact us for a new one.');
        }

        try {
            $booking = $this->quotationService->acceptQuotation($quotation);

            return $this->redirectToShow($quotation, 'success', 'Quotation accepted! Your booking is now being processed. Reference: ' . $booking->job_code);
        } catch (\Exception $e) {
            Log::error('Failed to accept quotation', [
                'quotation_id' => $quotation->id,
                'error'        => $e->getMessage(),
            ]);

            return $this->redirectToShow($quotation, 'error', 'Failed to accept quotation. Please try again or contact us.');
        }
    }

    public function reject(Request $request, Quotation $quotation)
    {
        if (!$request->hasValidSignature()) {
            abort(403, 'Invalid or expired link');
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        if ($quotation->status !== 'sent') {
            return $this->redirectToShow($quotation, 'error', 'This quotation has already been responded to.');
        }

        try {
            $this->quotationService->rejectQuotation($quotation, $validated['reason'] ?? null);

            return $this->redirectToShow($quotation, 'success', 'Quotation rejected. You can request a new quotation anytime.');
        } catch (\Exception $e) {
            Log::error('Failed to reject quotation', [
                'quotation_id' => $quotation->id,
                'error'        => $e->getMessage(),
            ]);

            return $this->redirectToShow($quotation, 'error', 'Failed to reject quotation. Please try again.');
        }
    }

    public function negotiate(Request $request, Quotation $quotation)
    {
        if (!$request->hasValidSignature()) {
            abort(403, 'Invalid or expired link');
        }

        $validated = $request->validate([
            'counter_offer' => 'nullable|numeric|min:0',
            'note'          => 'required|string|max:1000',
        ]);

        if ($quotation->status !== 'sent') {
            return $this->redirectToShow($quotation, 'error', 'This quotation has already been responded to.');
        }

        if ($quotation->isExpired()) {
            return $this->redirectToShow($quotation, 'error', 'This quotation has expired. Please contact us for a new one.');
        }

        try {
            $this->quotationService->negotiateQuotation(
                $quotation,
                $validated['counter_offer'] ?? null,
                $validated['note']
            );

            return $this->redirectToShow($quotation, 'success', 'Your negotiation request has been sent. We will get back to you shortly.');
        } catch (\Exception $e) {
            Log::error('Failed to negotiate quotation', [
                'quotation_id' => $quotation->id,
                'error'        => $e->getMessage(),
            ]);

            return $this->redirectToShow($quotation, 'error', 'Failed to submit negotiation. Please try again.');
        }
    }

    protected function redirectToShow(Quotation $quotation, string $type, string $message)
    {
        // Generate a fresh signed URL for the show page so redirect never 403s
        $signedShowUrl = URL::temporarySignedRoute(
            'quotation.show',
            now()->addMinutes(30),
            ['quotation' => $quotation->id]
        );

        return redirect()->to($signedShowUrl)->with($type, $message);
    }
}
