<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Concurrent requests (double-submit, retry-on-timeout) can both pass an
     * application-level is_current check before either write commits, producing
     * two "current" rows for the same quotation/booking. These partial unique
     * indexes make that impossible at the database level — the loser gets a
     * clean constraint violation instead of silently corrupting state.
     */
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX quotations_one_current_per_number ON quotations (quotation_number) WHERE is_current = true');
        DB::statement('CREATE UNIQUE INDEX invoices_one_current_per_booking ON invoices (booking_id) WHERE is_current = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS quotations_one_current_per_number');
        DB::statement('DROP INDEX IF EXISTS invoices_one_current_per_booking');
    }
};
