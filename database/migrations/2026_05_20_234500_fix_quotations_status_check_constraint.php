<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the old CHECK constraint created by the original enum definition
        DB::statement('ALTER TABLE quotations DROP CONSTRAINT IF EXISTS quotations_status_check');

        // Add a new CHECK constraint that includes all valid statuses
        DB::statement("
            ALTER TABLE quotations
            ADD CONSTRAINT quotations_status_check
            CHECK (status IN (
                'pending', 'draft', 'sent', 'accepted', 'rejected',
                'expired', 'disregarded', 'negotiating', 'cancelled'
            ))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE quotations DROP CONSTRAINT IF EXISTS quotations_status_check');

        DB::statement("
            ALTER TABLE quotations
            ADD CONSTRAINT quotations_status_check
            CHECK (status IN ('pending', 'sent', 'accepted', 'rejected', 'expired', 'disregarded'))
        ");
    }
};
