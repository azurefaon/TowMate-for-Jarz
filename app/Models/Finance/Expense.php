<?php

namespace App\Models\Finance;

use App\Casts\MoneyCast;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Team Leader submits -> Dispatcher verifies -> Owner approves. Approved expenses can only be voided or corrected
 * (void + linked replacement). How the cost was paid lives in fundings(); only the unit_fund split affects unit funds.
 */
class Expense extends Model
{
    use HasFinanceReference;

    public const REFERENCE_PREFIX = 'EXP';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_VOIDED = 'voided';

    public const PENDING_STATUSES = [self::STATUS_SUBMITTED, self::STATUS_VERIFIED];

    public const STATUS_LABELS = [
        self::STATUS_SUBMITTED => 'Awaiting Dispatcher review',
        self::STATUS_VERIFIED => 'Awaiting Owner approval',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Rejected',
        self::STATUS_VOIDED => 'Voided',
    ];

    protected $table = 'finance_expenses';

    protected $attributes = ['status' => self::STATUS_SUBMITTED, 'expense_scope' => 'job', 'unit_fund_shortfall' => '0.00'];

    protected $fillable = [
        'idempotency_key', 'unit_id', 'booking_id', 'expense_scope', 'fund_entry_id', 'corrects_expense_id', 'category',
        'description', 'amount', 'expense_date', 'coverage_month', 'funding_source', 'unit_fund_shortfall',
        'submitted_by', 'submitted_at',
    ];

    protected $casts = [
        'amount' => MoneyCast::class,
        'unit_fund_shortfall' => MoneyCast::class,
        'expense_date' => 'date',
        'coverage_month' => 'date',
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function fundEntry(): BelongsTo
    {
        return $this->belongsTo(FundEntry::class);
    }

    public function fundings(): HasMany
    {
        return $this->hasMany(ExpenseFunding::class)->orderBy('id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ExpenseEvidence::class)->orderBy('id');
    }

    public function correctedExpense(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_expense_id');
    }

    public function correction(): HasOne
    {
        return $this->hasOne(self::class, 'corrects_expense_id')->whereNotIn('status', [self::STATUS_REJECTED, self::STATUS_VOIDED]);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status);
    }

    public function categoryLabel(): string
    {
        return config('finance.expense_categories')[$this->category] ?? (string) $this->category;
    }

    public function fundingAmount(string $source): Money
    {
        $funding = $this->fundings->firstWhere('source', $source);

        return $funding ? Money::of($funding->amount) : Money::zero();
    }

    public function hasEvidence(): bool
    {
        return $this->relationLoaded('evidence') ? $this->evidence->isNotEmpty() : $this->evidence()->exists();
    }

    /**
     * Review flags shown next to the expense. None of them changes any balance; they mark rules still
     * awaiting client confirmation or facts the reviewer should see.
     *
     * @return array<string, string>
     */
    public function reviewFlags(): array
    {
        $flags = [];
        if (! $this->hasEvidence()) {
            $flags['missing_evidence'] = 'Receipt or payment evidence missing';
        }
        if (Money::of($this->unit_fund_shortfall)->isPositive()) {
            $flags['shortfall'] = 'Exceeded available unit funds by ' . Money::of($this->unit_fund_shortfall)->format() . ' at entry';
        }
        if ($this->expense_scope === 'unit') {
            $flags['monthly_allocation'] = 'Monthly allocation rule pending client confirmation';
        }
        if ($this->fundingAmount(ExpenseFunding::SOURCE_PERSONAL)->isPositive()) {
            $flags['personal_advance'] = 'Personal advance: reimbursement rule pending client confirmation';
        }

        return $flags;
    }
}
