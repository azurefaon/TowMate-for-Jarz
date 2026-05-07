<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['additional_fee', 'discount']);
        });
    }
};
