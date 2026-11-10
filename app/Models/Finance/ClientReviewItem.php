<?php

namespace App\Models\Finance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending client decision, requested revision or remark. Recording an answer never changes any policy or financial
 * record; a programmer implements answered decisions afterwards.
 */
class ClientReviewItem extends Model
{
    use HasFinanceReference;

    public const REFERENCE_PREFIX = 'CR';

    public const KINDS = [
        'decision' => 'Pending client decision',
        'revision' => 'Requested revision',
        'remark' => 'Remark',
    ];

    public const STATUSES = [
        'pending' => 'Pending client decision',
        'in_discussion' => 'In discussion',
        'answered' => 'Client answered — awaiting implementation',
        'revision_requested' => 'Revision requested',
        'closed' => 'Closed',
    ];

    protected $table = 'finance_client_review_items';

    protected $attributes = ['status' => 'pending'];

    protected $fillable = ['topic_code', 'kind', 'title', 'question', 'current_behaviour', 'blocks', 'client_response', 'requested_revision', 'owner_remarks', 'status', 'created_by', 'updated_by'];

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
