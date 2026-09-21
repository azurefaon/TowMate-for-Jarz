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
    protected const GLOBAL_IGNORED_FIELDS = [
        'updated_at',
        'remember_token',
        'password',
        'password_reset_otp_hash',
        'password_reset_otp_expires_at',
        'password_reset_attempts',
        'password_reset_resend_available_at',
        'password_reset_token_hash',
        'password_reset_token_expires_at',
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

    public const BUSINESS_ACTIONS = [
        'create_booking', 'update_booking', 'delete_booking',
        'booking_assigned', 'booking_reassigned', 'booking_status_override',
        'booking_cancelled_by_customer', 'booking_rejected', 'booking_rescheduled',
        'scheduled_booking_cancelled_by_dispatcher', 'demo_arrival_confirmed',
        'payment_confirmed', 'payment_submitted', 'service_fee_applied', 'invoice_voided',
        'create_quotation', 'update_quotation', 'delete_quotation',
        'quotation_draft_updated', 'quotation_drafted', 'quotation_price_updated',
        'quotation_price_review_adjusted', 'quotation_price_review_kept', 'quotation_sent',
        'create_unit', 'unit_created', 'update_unit', 'unit_archived', 'unit_restored',
        'unit_permanently_deleted', 'unit_status_override', 'unit_assigned', 'unit_removed',
        'crew_borrowed', 'crew_returned', 'crew_removed',
        'team_leader_reassigned', 'team_leader_assigned', 'team_leader_removed', 'team_leader_returned',
        'team_leader_duty_changed', 'team_leader_status_override', 'team_transferred', 'slot_duty_changed',
        'driver_added',
        'create_truck_type', 'update_truck_type', 'delete_truck_type',
        'create_customer', 'update_customer', 'delete_customer',
        'mobile_service_created', 'mobile_service_reordered', 'mobile_service_status_changed',
        'mobile_announcement_created', 'mobile_announcement_updated', 'mobile_announcement_status_changed',
        'mobile_coverage_area_created', 'mobile_coverage_area_reordered', 'mobile_coverage_area_status_changed',
        'mobile_how_it_works_step_created', 'mobile_how_it_works_step_reordered', 'mobile_how_it_works_step_status_changed',
        'mobile_about_updated', 'mobile_support_updated',
        'customer_risk_updated',
        'personnel_created', 'personnel_updated', 'personnel_activated',
        'personnel_deactivated', 'personnel_home_unit_changed',
    ];

    public static function isBusinessAction(string $action): bool
    {
        return in_array($action, self::BUSINESS_ACTIONS, true);
    }

    public static function categoryForAction(string $action): string
    {
        return match (true) {
            $action === 'failed_login' => 'login_failed',
            str_starts_with($action, 'customer_registration_otp_')
                || str_starts_with($action, 'customer_password_reset_otp_')
                || $action === 'customer_password_reset_completed'
                || str_starts_with($action, 'staff_password_reset_otp_')
                || $action === 'staff_password_reset_completed' => 'security',
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

        if (is_array($raw)) {
            return json_encode($raw) ?: '—';
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

    public function describeCreate(Model $model, string $actorName): array
    {
        $modelLabel = method_exists($model, 'auditLabel') ? $model->auditLabel() : class_basename($model) . ' #' . $model->getKey();

        return [
            'category' => 'create',
            'description' => "{$actorName} created {$modelLabel}.",
        ];
    }

    public function describeDelete(Model $model, string $actorName): array
    {
        $modelLabel = method_exists($model, 'auditLabel') ? $model->auditLabel() : class_basename($model) . ' #' . $model->getKey();

        return [
            'category' => 'delete',
            'description' => "{$actorName} deleted {$modelLabel}.",
        ];
    }

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
