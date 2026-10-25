<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * audit_logs.user_id used to be ON DELETE CASCADE, so deleting a user erased the
 * audit trail of everything that user did. Switch it to ON DELETE SET NULL (the
 * column is already nullable) and add a small non-personal actor_label that is
 * stamped just before a user is deleted, so the entry still says "a deleted
 * account, role N" without keeping the person's name or contact details.
 *
 * Existing rows are not touched. On PostgreSQL the new constraint is added NOT
 * VALID and then validated, which keeps the lock on audit_logs/users short.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_logs', 'actor_label')) {
                $table->string('actor_label', 120)->nullable()->after('user_id');
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::transaction(function () {
                DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_user_id_foreign');
                DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT VALID');
                DB::statement('ALTER TABLE audit_logs VALIDATE CONSTRAINT audit_logs_user_id_foreign');
            });

            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::transaction(function () {
                DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_user_id_foreign');
                DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
            });
        } else {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            if (Schema::hasColumn('audit_logs', 'actor_label')) {
                $table->dropColumn('actor_label');
            }
        });
    }
};
