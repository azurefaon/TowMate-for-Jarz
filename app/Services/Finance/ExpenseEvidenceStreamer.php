<?php

namespace App\Services\Finance;

use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseEvidence;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves evidence from the private disk after the caller's access to the expense is checked.
 * Files are never reachable by URL on their own; every request goes through this check.
 */
class ExpenseEvidenceStreamer
{
    public function __construct(private readonly ExpenseService $expenses)
    {
    }

    public function stream(User $actor, int $expenseId, int $evidenceId): StreamedResponse
    {
        $expense = Expense::findOrFail($expenseId);
        $evidence = ExpenseEvidence::where('expense_id', $expense->id)->findOrFail($evidenceId);

        abort_unless($this->expenses->canAccess($actor, $expense), 403);
        abort_unless(Storage::disk($evidence->disk)->exists($evidence->path), 404);

        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($evidence->original_name ?: basename($evidence->path)));

        return Storage::disk($evidence->disk)->response($evidence->path, $name, [
            'Content-Type' => $evidence->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], 'inline');
    }
}
