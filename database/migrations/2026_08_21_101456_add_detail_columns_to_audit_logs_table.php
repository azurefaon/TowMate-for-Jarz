<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('category', 30)->nullable()->after('action');
            $table->json('old_value')->nullable()->after('description');
            $table->json('new_value')->nullable()->after('old_value');
            $table->string('ip_address', 45)->nullable()->after('new_value');
            $table->string('user_agent', 255)->nullable()->after('ip_address');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['category', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
        });

        // Backfill category on existing rows so historical entries are filterable
        // immediately, chunked to stay safe on a large table.
        DB::table('audit_logs')->whereNull('category')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('audit_logs')
                    ->where('id', $row->id)
                    ->update(['category' => $this->categoryForAction((string) $row->action)]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['category', 'created_at']);
            $table->dropIndex(['entity_type', 'entity_id']);
            $table->dropColumn(['category', 'old_value', 'new_value', 'ip_address', 'user_agent']);
        });
    }

    /**
     * Same classification rules as App\Services\AuditLogService::categoryForAction().
     * Duplicated here deliberately so this migration stays self-contained and safe
     * to replay even if the service class changes shape later.
     */
    protected function categoryForAction(string $action): string
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
};
