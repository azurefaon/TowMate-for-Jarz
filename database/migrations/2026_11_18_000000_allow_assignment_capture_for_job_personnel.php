<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Job crew can now be captured automatically when Dispatch assigns a unit ("assignment_capture").
 * Only the allowed values of finance_job_personnel.source change; no rows are created, altered or backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE finance_job_personnel DROP CONSTRAINT fjp_source');
        DB::statement("ALTER TABLE finance_job_personnel ADD CONSTRAINT fjp_source CHECK (source IN ('completion_capture', 'backfill', 'manual', 'assignment_capture'))");
    }

    public function down(): void
    {
        // Development rollback only: fails by design if assignment captures exist.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE finance_job_personnel DROP CONSTRAINT fjp_source');
        DB::statement("ALTER TABLE finance_job_personnel ADD CONSTRAINT fjp_source CHECK (source IN ('completion_capture', 'backfill', 'manual'))");
    }
};
