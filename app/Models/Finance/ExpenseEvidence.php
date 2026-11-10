<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A receipt file on the private disk. The storage path is never serialised. */
class ExpenseEvidence extends Model
{
    protected $table = 'finance_expense_evidence';

    protected $fillable = ['expense_id', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'uploaded_by'];

    protected $hidden = ['disk', 'path'];

    protected $casts = ['size_bytes' => 'integer'];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
