<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $now = now();

        DB::table('roles')->insertOrIgnore([
            ['id' => 5, 'name' => 'Customer', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Advance PostgreSQL sequence so auto-increment doesn't collide
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('roles', 'id'), GREATEST((SELECT MAX(id) FROM roles), 1))");
        }
    }

    public function down(): void
    {
        DB::table('roles')->where('id', 5)->delete();
    }
};
