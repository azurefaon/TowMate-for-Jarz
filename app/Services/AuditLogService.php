<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\Zone;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogService
{
    /**
     * Fields on auditable models that are never logged, either because they
     * are secrets or because they change too often to be meaningful (GPS
     * pings, presence heartbeats).
     */
    protected const GLOBAL_IGNORED_FIELDS = [
        'updated_at',
        'remember_token',
        'password',
        'password_reset_token',
        'password_reset_otp',
        'password_reset_otp_expires_at',
        'email_verified_at',
    ];

    protected const MODEL_IGNORED_FIELDS = [
        'Unit' => ['current_lat', 'current_lng', 'location_accuracy', 'location_updated_at', 'last_updated_at'],
        'User' => ['last_ping_at'],
    ];

    protected const FIELD_LABELS = [
        'assigned_unit_id' => 'assigned unit',
        'assigned_team_leader_id' => 'assigned team leader',
        'returned_by_team_leader_id' => 'return handler',
        'created_by_admin_id' => 'created by',
        'truck_type_id' => 'truck type',
        'vehicle_type_id' => 'vehicle type',
        'zone_id' => 'zone',
        'customer_id' => 'customer',
        'quotation_id' => 'quotation',
        'role_id' => 'role',
        'team_leader_id' => 'team leader',
        'driver_id' => 'driver',
        'full_name' => 'name',
        'final_total' => 'total amount',
        'computed_total' => 'computed total',
        'additional_fee' => 'additional fee',
        'archived_at' => 'archived state',
        'status' => 'status',
        'risk_level' => 'risk level',
        'dispatcher_status' => 'dispatcher status',
    ];

    /**
     * Resolves a foreign-key-style field to a related model class + the
     * attribute to display instead of the raw id.
     */
    protected const FIELD_RELATIONS = [
        'assigned_unit_id' => [Unit::class, 'name'],
        'team_leader_id' => [User::class, 'full_name'],
        'assigned_team_leader_id' => [User::class, 'full_name'],
        'returned_by_team_leader_id' => [User::class, 'full_name'],
        'created_by_admin_id' => [User::class, 'full_name'],
        'driver_id' => [User::class, 'full_name'],
        'truck_type_id' => [TruckType::class, 'name'],
        'vehicle_type_id' => [VehicleType::class, 'name'],
        'zone_id' => [Zone::class, 'name'],
    ];

    public static function categoryForAction(string $action): string
    {
        return match (true) {
            $action === 'failed_login' => 'login_failed',
            str_contains($action, 'archived') => 'archive',
            str_contains($action, 'restored') => 'restore',
            str_contains($action, 'purged') || str_contains($action, 'permanently_deleted') || str_contains($action, 'deleted') || str_contains($action, 'anonymized') => 'delete',
            str_contains($action, 'registered') || str_contains($action, 'created') => 'create',
            in_array($action, ['booking_assigned', 'booking_reassigned', 'unit_assigned', 'unit_removed', 'crew_borrowed', 'crew_returned', 'team_leader_reassigned'], true) => 'assignment_change',
            str_starts_with($action, 'quotation_') => 'quotation_change',
            str_contains($action, 'status') || $action === 'payment_confirmed' => 'status_change',
            str_starts_with($action, 'password_') || str_contains($action, 'backup') => 'system',
            str_contains($action, 'updated') => 'update',
            default => 'system',
        };
    }

    public static function ignoredFieldsFor(string $modelBasename): array
    {
        return array_merge(self::GLOBAL_IGNORED_FIELDS, self::MODEL_IGNORED_FIELDS[$modelBasename] ?? []);
    }

    /**
     * Request-scoped registry of entity => AuditLog id, populated whenever an
     * AuditLog row is inserted (manual call site or our own observer). Lets
     * AuditObserver::flush() tell whether a manual AuditLog::create() already
     * fired this request for a given entity, so it enriches that row instead
     * of inserting a duplicate.
     *
     * @var array<string,int>
     */
    protected static array $loggedEntities = [];

    public static function rememberLoggedEntity(?string $entityType, mixed $entityId, int $logId): void
    {
        if (blank($entityType) || blank($entityId)) {
            return;
        }

        static::$loggedEntities["{$entityType}:{$entityId}"] = $logId;
    }

    public static function findLoggedEntityId(string $entityType, mixed $entityId): ?int
    {
        return static::$loggedEntities["{$entityType}:{$entityId}"] ?? null;
    }

    public static function resetRequestRegistry(): void
    {
        static::$loggedEntities = [];
    }

    public function logLogin(User $user, Request $request, string $guard): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'login',
            'category' => 'login',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'reference' => $user->user_code,
            'description' => "{$user->full_name} logged in as {$user->role?->name} ({$guard}).",
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }

    public function logLogout(User $user, Request $request): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'logout',
            'category' => 'logout',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'reference' => $user->user_code,
            'description' => "{$user->full_name} logged out.",
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }

    public function friendlyFieldLabel(string $field): string
    {
        if (isset(self::FIELD_LABELS[$field])) {
            return self::FIELD_LABELS[$field];
        }

        return (string) Str::of($field)
            ->replace('_id', '')
            ->replace('_', ' ')
            ->trim();
    }

    public function resolveDisplayValue(string $field, mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '—';
        }

        if (isset(self::FIELD_RELATIONS[$field])) {
            [$relatedClass, $attribute] = self::FIELD_RELATIONS[$field];

            /** @var Model|null $related */
            $related = $relatedClass::find($raw);

            return $related?->{$attribute} ?? "#{$raw}";
        }

        if (is_bool($raw)) {
            return $raw ? 'Yes' : 'No';
        }

        if (in_array($raw, ['0', '1'], true) && str_starts_with($field, 'is_')) {
            return $raw === '1' ? 'Yes' : 'No';
        }

        if (str_ends_with($field, '_at') && $this->looksLikeDate($raw)) {
            return Carbon::parse($raw)->format('M j, Y g:i A');
        }

        if (in_array($field, ['final_total', 'computed_total', 'additional_fee', 'base_rate', 'vat_amount', 'discount_percentage'], true) && is_numeric($raw)) {
            return '₱' . number_format((float) $raw, 2);
        }

        return (string) $raw;
    }

    protected function looksLikeDate(mixed $raw): bool
    {
        if (! is_string($raw)) {
            return false;
        }

        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', $raw);
    }

    /**
     * Builds the human-readable sentence(s) describing a model change, and
     * derives the semantic category (status_change / assignment_change /
     * quotation_change / archive / restore / update) from the fields that
     * actually changed rather than needing a bespoke call site per action.
     *
     * @param  array<string,mixed>  $changed  new values, keyed by field
     * @param  array<string,mixed>  $original  old values, keyed by field
     * @return array{category:string,description:string}
     */
    public function describeChanges(Model $model, array $changed, array $original, string $actorName): array
    {
        $modelLabel = method_exists($model, 'auditLabel') ? $model->auditLabel() : class_basename($model) . ' #' . $model->getKey();

        $category = $this->categoryFor($model, $changed, $original);

        $sentences = [];

        foreach ($changed as $field => $newRaw) {
            $oldRaw = $original[$field] ?? null;
            $label = $this->friendlyFieldLabel($field);
            $oldDisplay = $this->resolveDisplayValue($field, $oldRaw);
            $newDisplay = $this->resolveDisplayValue($field, $newRaw);

            $sentences[] = "{$actorName} changed {$label} of {$modelLabel} from \"{$oldDisplay}\" to \"{$newDisplay}\".";
        }

        return [
            'category' => $category,
            'description' => implode(' ', $sentences),
        ];
    }

    /**
     * @return array{category:string,description:string}
     */
    public function describeCreate(Model $model, string $actorName): array
    {
        $modelLabel = method_exists($model, 'auditLabel') ? $model->auditLabel() : class_basename($model) . ' #' . $model->getKey();

        return [
            'category' => 'create',
            'description' => "{$actorName} created {$modelLabel}.",
        ];
    }

    /**
     * @return array{category:string,description:string}
     */
    public function describeDelete(Model $model, string $actorName): array
    {
        $modelLabel = method_exists($model, 'auditLabel') ? $model->auditLabel() : class_basename($model) . ' #' . $model->getKey();

        return [
            'category' => 'delete',
            'description' => "{$actorName} deleted {$modelLabel}.",
        ];
    }

    /**
     * @param  array<string,mixed>  $changed
     * @param  array<string,mixed>  $original
     */
    protected function categoryFor(Model $model, array $changed, array $original): string
    {
        if (array_key_exists('archived_at', $changed)) {
            return blank($original['archived_at'] ?? null) ? 'archive' : 'restore';
        }

        if (array_key_exists('status', $changed)) {
            return 'status_change';
        }

        if (array_key_exists('assigned_unit_id', $changed) || array_key_exists('assigned_team_leader_id', $changed)) {
            return 'assignment_change';
        }

        if (class_basename($model) === 'Quotation') {
            return 'quotation_change';
        }

        return 'update';
    }
}
