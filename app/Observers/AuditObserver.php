<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Generic create/update/delete auditing for the models registered in
 * AppServiceProvider::boot(). Diffs are queued in-memory during the request
 * and reconciled once at shutdown (see flush()), so a model change that a
 * controller already logged by hand gets enriched with old/new values
 * instead of producing a duplicate row.
 */
class AuditObserver
{
    /**
     * @var array<int,array{model:Model,entity_type:string,entity_id:mixed,event:string,changed:array,original:array}>
     */
    protected static array $pending = [];

    public function created(Model $model): void
    {
        $this->queue($model, 'create', $this->filterFields($model, $model->getAttributes()), []);
    }

    public function updated(Model $model): void
    {
        $changed = $this->filterFields($model, $model->getChanges());

        if (empty($changed)) {
            return;
        }

        $original = array_intersect_key($model->getOriginal(), $changed);

        $this->queue($model, 'update', $changed, $original);
    }

    public function deleted(Model $model): void
    {
        $this->queue($model, 'delete', [], $this->filterFields($model, $model->getAttributes()));
    }

    protected function filterFields(Model $model, array $attributes): array
    {
        $ignored = AuditLogService::ignoredFieldsFor(class_basename($model));

        return array_diff_key($attributes, array_flip(array_merge($ignored, ['id', 'created_at'])));
    }

    /**
     * The model instance is cloned (not reconstructed) so auditLabel() and
     * other accessors keep full access to every real attribute, not just
     * the ones that happened to change.
     */
    protected function queue(Model $model, string $event, array $changed, array $original): void
    {
        static::$pending[] = [
            'model' => clone $model,
            'entity_type' => class_basename($model),
            'entity_id' => $model->getKey(),
            'event' => $event,
            'changed' => $changed,
            'original' => $original,
        ];
    }

    /**
     * Reconciles every queued diff against this request's manual audit-log
     * writes: enrich a matching row if one already exists, otherwise insert
     * a freshly generated one. Call once, at end of request.
     */
    public static function flush(): void
    {
        if (empty(static::$pending)) {
            AuditLogService::resetRequestRegistry();

            return;
        }

        $service = app(AuditLogService::class);
        $actorName = auth()->user()?->full_name ?? auth()->user()?->name ?? 'System';

        foreach (static::$pending as $entry) {
            $model = $entry['model'];

            $result = match ($entry['event']) {
                'create' => $service->describeCreate($model, $actorName),
                'delete' => $service->describeDelete($model, $actorName),
                default => $service->describeChanges($model, $entry['changed'], $entry['original'], $actorName),
            };

            $existingId = AuditLogService::findLoggedEntityId($entry['entity_type'], $entry['entity_id']);

            if ($existingId) {
                $existing = AuditLog::find($existingId);

                if ($existing && blank($existing->old_value) && blank($existing->new_value)) {
                    $existing->update([
                        'category' => $existing->category ?: $result['category'],
                        'old_value' => $entry['original'] ?: null,
                        'new_value' => $entry['changed'] ?: null,
                    ]);

                    continue;
                }
            }

            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => $entry['event'] . '_' . Str::snake($entry['entity_type']),
                'category' => $result['category'],
                'entity_type' => $entry['entity_type'],
                'entity_id' => $entry['entity_id'],
                'reference' => method_exists($model, 'auditLabel') ? $model->auditLabel() : null,
                'description' => $result['description'],
                'old_value' => $entry['original'] ?: null,
                'new_value' => $entry['changed'] ?: null,
            ]);
        }

        static::$pending = [];
        AuditLogService::resetRequestRegistry();
    }
}
