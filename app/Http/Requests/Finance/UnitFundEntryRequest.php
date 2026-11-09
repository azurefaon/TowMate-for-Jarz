<?php

namespace App\Http\Requests\Finance;

use App\Support\Finance\FinanceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Issue funds, record returned funds, or correct an entry. Amounts are validated as decimal STRINGS
 * (never cast to float). The service re-checks every business rule inside a locked transaction.
 */
class UnitFundEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows(FinanceAccess::MANAGE_FUNDS);
    }

    protected function prepareForValidation(): void
    {
        $amount = $this->input('amount');
        if (is_string($amount)) {
            $this->merge(['amount' => str_replace([',', '₱', ' '], '', trim($amount))]);
        }
    }

    public function rules(): array
    {
        $today = now(config('finance.timezone'))->toDateString();
        $isIssue = $this->routeIs('superadmin.finance.unit-funds.issue');
        $isCorrection = $this->routeIs('superadmin.finance.unit-funds.correct');

        return array_filter([
            'unit_id' => $isCorrection ? null : ['required', 'integer', Rule::exists('units', 'id')],
            'issue_kind' => $isIssue ? ['required', Rule::in(array_keys(config('finance.issue_kinds')))] : null,
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $today],
            'amount' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'counterparty_name' => ['required', 'string', 'max:150'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => $isCorrection ? ['required', 'string', 'max:500'] : null,
            'idempotency_key' => ['required', 'uuid'],
        ]);
    }

    public function messages(): array
    {
        return [
            'amount.regex' => 'Enter a valid amount with at most two decimal places, for example 3000 or 2950.50.',
            'entry_date.before_or_equal' => 'Fund movements cannot be dated in the future.',
            'counterparty_name.required' => 'Enter the name of the person who received or returned the cash.',
            'issue_kind.required' => 'Choose whether these are initial or additional funds.',
            'reason.required' => 'Enter the reason for the correction.',
        ];
    }

    public function attributes(): array
    {
        return [
            'unit_id' => 'unit',
            'entry_date' => 'date',
            'counterparty_name' => 'person',
            'external_reference' => 'reference',
            'notes' => 'remarks',
        ];
    }
}
