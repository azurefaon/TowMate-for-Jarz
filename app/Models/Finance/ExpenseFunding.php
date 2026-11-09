<?php

namespace App\Models\Finance;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One funding split of an expense. All splits of an expense sum exactly to its amount. */
class ExpenseFunding extends Model
{
    public const SOURCE_UNIT_FUND = 'unit_fund';
    public const SOURCE_COMPANY = 'company_direct';
    public const SOURCE_PERSONAL = 'personal_advance';

    protected $table = 'finance_expense_fundings';

    protected $fillable = ['expense_id', 'source', 'amount', 'fund_entry_id'];

    protected $casts = ['amount' => MoneyCast::class];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function label(): string
    {
        return config('finance.funding_sources')[$this->source] ?? $this->source;
    }
}
