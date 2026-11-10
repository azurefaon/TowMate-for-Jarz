<?php

namespace App\Http\Requests\Finance;

use App\Support\Finance\FinanceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Record or correct an expense (Owner web form and Team Leader API). Amounts stay decimal STRINGS. The service
 * re-checks every rule (funding total, scope, unit ownership, evidence) inside a locked transaction.
 */
class ExpenseRequest extends FormRequest
{
    private const AMOUNT = 'regex:/^\d{1,10}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        return $this->isCorrection()
            ? Gate::allows(FinanceAccess::APPROVE_EXPENSE)
            : Gate::allows(FinanceAccess::SUBMIT_EXPENSE);
    }

    protected function prepareForValidation(): void
    {
        $strip = fn ($v) => is_string($v) ? str_replace([',', '₱', ' '], '', trim($v)) : $v;

        $merge = [];
        // Team Leader app: the unit is always the Team Leader's own unit, never taken from the request.
        if ($this->routeIs('api.tl.expenses.store')) {
            $merge['unit_id'] = optional($this->user()?->unit)->id;
        }
        if ($this->has('amount')) {
            $merge['amount'] = $strip($this->input('amount'));
        }
        if (is_array($this->input('fundings'))) {
            $merge['fundings'] = array_map($strip, $this->input('fundings'));
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        $today = now(config('finance.timezone'))->toDateString();
        $evidence = config('finance.evidence');

        return array_filter([
            'unit_id' => $this->isCorrection() ? null : ['required', 'integer', Rule::exists('units', 'id')],
            'expense_scope' => ['required', Rule::in(array_keys(config('finance.expense_scopes')))],
            'booking_ref' => ['nullable', 'required_if:expense_scope,job', 'string', 'max:40'],
            'coverage_month' => ['nullable', 'date_format:Y-m'],
            'expense_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $today],
            'category' => ['required', Rule::in(array_keys(config('finance.expense_categories')))],
            'description' => ['required', 'string', 'max:2000'],
            'amount' => ['required', 'string', self::AMOUNT],
            'fundings' => ['required', 'array'],
            'fundings.unit_fund' => ['nullable', 'string', self::AMOUNT],
            'fundings.company_direct' => ['nullable', 'string', self::AMOUNT],
            'fundings.personal_advance' => ['nullable', 'string', self::AMOUNT],
            'receipts' => ['nullable', 'array', 'max:' . $evidence['max_files_per_upload']],
            'receipts.*' => ['file', 'mimes:' . implode(',', $evidence['extensions']), 'mimetypes:' . implode(',', $evidence['mimes']), 'max:' . $evidence['max_kilobytes']],
            'reason' => $this->isCorrection() ? ['required', 'string', 'max:500'] : null,
            'idempotency_key' => ['required', 'uuid'],
        ]);
    }

    public function messages(): array
    {
        return [
            'amount.regex' => 'Enter a valid amount with at most two decimal places, for example 2950 or 2950.50.',
            'fundings.*.regex' => 'Enter each funding amount with at most two decimal places.',
            'fundings.required' => 'Say how the expense was paid.',
            'booking_ref.required_if' => 'A job expense needs the booking reference.',
            'expense_date.before_or_equal' => 'Expenses cannot be dated in the future.',
            'receipts.*.mimes' => 'Receipts must be JPG, PNG, WEBP or PDF files.',
            'receipts.*.mimetypes' => 'Receipts must be JPG, PNG, WEBP or PDF files.',
            'receipts.*.max' => 'Each receipt must be smaller than ' . intdiv(config('finance.evidence.max_kilobytes'), 1024) . ' MB.',
            'reason.required' => 'Enter the reason for the correction.',
        ];
    }

    public function attributes(): array
    {
        return ['unit_id' => 'unit', 'expense_scope' => 'expense type', 'booking_ref' => 'booking reference', 'expense_date' => 'date'];
    }

    /** The validated data in the shape ExpenseService expects. */
    public function expenseData(): array
    {
        $v = $this->validated();

        return [
            'unit_id' => $v['unit_id'] ?? null,
            'expense_scope' => $v['expense_scope'],
            'booking_id' => $v['expense_scope'] === 'job' ? ($v['booking_ref'] ?? null) : null,
            'coverage_month' => $v['coverage_month'] ?? null,
            'expense_date' => $v['expense_date'],
            'category' => $v['category'],
            'description' => $v['description'],
            'amount' => $v['amount'],
            'fundings' => $v['fundings'] ?? [],
        ];
    }

    private function isCorrection(): bool
    {
        return $this->routeIs('superadmin.finance.expenses.correct');
    }
}
