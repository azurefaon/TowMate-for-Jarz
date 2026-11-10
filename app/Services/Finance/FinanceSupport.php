<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Shared plumbing for the finance services: authorization, idempotent creation and audit rows. */
abstract class FinanceSupport
{
    protected function authorize(User $actor, string $ability): void
    {
        Gate::forUser($actor)->authorize($ability);
    }

    /**
     * Creates a row at most once per idempotency key. A retry with the same key and the same data returns
     * the original row. The same key with DIFFERENT data is refused, because it means two different
     * transactions are claiming one submission.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, string|int|null>  $fingerprint  the fields that must match on a replay
     */
    protected function createOnce(string $modelClass, string $idempotencyKey, array $fingerprint, Closure $create): Model
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $idempotencyKey)) {
            throw new FinanceException('A valid idempotency key (UUID) is required.');
        }

        $existing = $modelClass::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $this->assertSameSubmission($existing, $fingerprint);
        }

        try {
            return DB::transaction($create);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key won the race.
            $existing = $modelClass::where('idempotency_key', $idempotencyKey)->first();
            if (! $existing) {
                throw $e;
            }

            return $this->assertSameSubmission($existing, $fingerprint);
        }
    }

    private function assertSameSubmission(Model $existing, array $fingerprint): Model
    {
        foreach ($fingerprint as $field => $value) {
            $stored = $existing->getAttribute($field);
            $stored = $stored instanceof \DateTimeInterface ? $stored->format('Y-m-d') : $stored;
            if ((string) $stored !== (string) $value) {
                throw new FinanceException('This submission was already recorded with different details. Refresh the page and try again.');
            }
        }

        return $existing;
    }

    protected function audit(User $actor, string $action, Model $entity, string $reference, string $description, array $newValue = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => $actor->id,
            'actor_label' => $actor->full_name ?? $actor->name,
            'action' => $action,
            'entity_type' => class_basename($entity),
            'entity_id' => $entity->getKey(),
            'reference' => $reference,
            'description' => $description,
            'new_value' => $newValue ?: null,
        ]);
    }

    protected function actorName(User $actor): string
    {
        return (string) ($actor->full_name ?: $actor->name ?: ('User #' . $actor->id));
    }
}
