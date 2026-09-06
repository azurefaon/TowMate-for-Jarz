<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guards against fresh installs (e.g. a test database) where this
        // file's timestamp sorts before 2026_02_27_170000_create_quotations_table.php.
        // On databases where this migration already ran, its recorded row is
        // untouched and this content is never re-executed.
        if (!Schema::hasTable('quotations')) {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            if (!Schema::hasColumn('quotations', 'additional_fee')) {
                $table->decimal('additional_fee', 10, 2)->default(0)->after('estimated_price');
            }
            if (!Schema::hasColumn('quotations', 'discount')) {
                $table->decimal('discount', 10, 2)->default(0)->after('additional_fee');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('quotations')) {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            $columns = array_filter(['additional_fee', 'discount'], fn ($column) => Schema::hasColumn('quotations', $column));
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
