<?php

namespace App\Services\Finance;

use App\Models\Finance\ClientReviewItem;
use App\Models\User;
use App\Support\Finance\FinanceAccess;
use Illuminate\Support\Facades\DB;

/**
 * Owner-only Client Review register (pending decisions, requested revisions, remarks).
 *
 * Deliberately inert: answering a decision here records the client's words and a status. It does NOT confirm a
 * commission policy, change a rule, or alter any financial record. Those changes are made by a programmer, after which
 * the item can be closed. Every change is audited with the previous and new values.
 */
class ClientReviewService extends FinanceSupport
{
    private const TEXT_FIELDS = ['title' => 200, 'question' => 4000, 'current_behaviour' => 4000, 'blocks' => 2000, 'client_response' => 4000, 'requested_revision' => 4000, 'owner_remarks' => 4000];

    public function create(User $actor, array $data): ClientReviewItem
    {
        $this->authorize($actor, FinanceAccess::MANAGE_POLICIES);
        $kind = (string) ($data['kind'] ?? '');
        if (! isset(ClientReviewItem::KINDS[$kind])) {
            throw new FinanceException('Choose whether this is a decision, a requested revision or a remark.');
        }
        $clean = $this->clean($data);
        if (($clean['title'] ?? '') === '') {
            throw new FinanceException('Enter a short title.');
        }

        return DB::transaction(function () use ($actor, $kind, $clean) {
            $item = ClientReviewItem::create($clean + [
                'kind' => $kind,
                'status' => $kind === 'revision' ? 'revision_requested' : 'pending',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->audit($actor, 'finance_client_review_created', $item, $item->reference_code, "Added client review item: {$item->title}", $clean);

            return $item;
        });
    }

    public function update(User $actor, ClientReviewItem $item, array $data): ClientReviewItem
    {
        $this->authorize($actor, FinanceAccess::MANAGE_POLICIES);
        $status = $data['status'] ?? $item->status;
        if (! isset(ClientReviewItem::STATUSES[$status])) {
            throw new FinanceException('Choose a valid review status.');
        }

        return DB::transaction(function () use ($actor, $item, $data, $status) {
            $locked = ClientReviewItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $changes = array_intersect_key($this->clean($data), array_flip(['client_response', 'requested_revision', 'owner_remarks']));
            if ($status === 'answered' && trim((string) ($changes['client_response'] ?? $locked->client_response)) === '') {
                throw new FinanceException('Record the client\'s answer before marking the item answered.');
            }
            $changes['status'] = $status;

            $before = array_intersect_key($locked->only(array_keys($changes)), $changes);
            $locked->forceFill($changes + ['updated_by' => $actor->id])->save();

            $this->audit($actor, 'finance_client_review_updated', $locked, $locked->reference_code,
                "Updated client review item {$locked->reference_code} ({$locked->statusLabel()}). No policy or financial record was changed.",
                ['before' => $before, 'after' => $changes]);

            return $locked;
        });
    }

    private function clean(array $data): array
    {
        $out = [];
        foreach (self::TEXT_FIELDS as $field => $max) {
            if (array_key_exists($field, $data)) {
                $value = trim((string) $data[$field]);
                $out[$field] = $value === '' ? null : mb_substr($value, 0, $max);
            }
        }
        if (array_key_exists('title', $out)) {
            $out['title'] = (string) $out['title'];
        }

        return $out;
    }
}
