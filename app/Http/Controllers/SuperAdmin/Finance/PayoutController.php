<?php

namespace App\Http\Controllers\SuperAdmin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\CommissionStatement;
use App\Models\Finance\Payout;
use App\Services\Finance\FinanceException;
use App\Services\Finance\PayoutService;
use App\Support\Finance\FinanceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Owner > Finance > Commission Statements > Payouts. Records payouts the Owner already made outside TowMate; choosing
 * a method never starts a transfer and never marks the statement Paid.
 */
class PayoutController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    public function __construct(private readonly PayoutService $payouts)
    {
    }

    public function store(Request $request, int $statement): RedirectResponse
    {
        $this->authorize(FinanceAccess::RECORD_PAYOUTS);
        $data = $this->validated($request) + $request->validate(['recipient_key' => ['required', 'string', 'max:60']]);

        try {
            $payout = $this->payouts->record($request->user(), CommissionStatement::findOrFail($statement), $data['recipient_key'], $data['payout_method'],
                $data['paid_at'], $data['idempotency_key'], $data['external_reference'] ?? null, $request->file('evidence'), $data['notes'] ?? null);
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()])->withInput($request->except('evidence'));
        }

        return back()->with('success', "Recorded payout {$payout->reference_code} of " . \App\Support\Money::of($payout->amount)->format() . " to {$payout->recipient_name}. No money was transferred by TowMate.");
    }

    public function void(Request $request, int $payout): RedirectResponse
    {
        $this->authorize(FinanceAccess::RECORD_PAYOUTS);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $model = $this->payouts->void($request->user(), Payout::findOrFail($payout), $data['reason']);
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()]);
        }

        return back()->with('success', "Voided payout {$model->reference_code}. It stays in the payout history.");
    }

    public function correct(Request $request, int $payout): RedirectResponse
    {
        $this->authorize(FinanceAccess::RECORD_PAYOUTS);
        $data = $this->validated($request) + $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $new = $this->payouts->correct($request->user(), Payout::findOrFail($payout), $data['reason'], $data['payout_method'], $data['paid_at'],
                $data['idempotency_key'], $data['external_reference'] ?? null, $request->file('evidence'), $data['notes'] ?? null);
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()]);
        }

        return back()->with('success', "Corrected: {$new->reference_code} replaces the voided payout, which stays in the history.");
    }

    public function markPaid(Request $request, int $statement): RedirectResponse
    {
        $this->authorize(FinanceAccess::RECORD_PAYOUTS);

        try {
            $model = $this->payouts->markPaid($request->user(), CommissionStatement::findOrFail($statement));
        } catch (FinanceException $e) {
            return back()->withErrors(['finance' => $e->getMessage()]);
        }

        return back()->with('success', "Statement {$model->reference_code} is marked Paid. Its payouts are now final.");
    }

    public function evidence(Request $request, int $payout): StreamedResponse
    {
        $this->authorize(FinanceAccess::VIEW);
        $model = Payout::findOrFail($payout);
        abort_unless($model->evidence_path && Storage::disk('local')->exists($model->evidence_path), 404);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($model->evidence_original_name ?: basename($model->evidence_path)));

        return Storage::disk('local')->response($model->evidence_path, $name, [
            'Content-Type' => $model->evidence_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], 'inline');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'payout_method' => ['required', Rule::in(array_keys(config('finance.payout_methods')))],
            'paid_at' => ['required', 'date'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'evidence' => ['nullable', 'file', 'max:' . config('finance.evidence.max_kilobytes'), 'mimes:' . implode(',', config('finance.evidence.extensions'))],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
    }
}
