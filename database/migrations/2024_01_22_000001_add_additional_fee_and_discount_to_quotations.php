<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->decimal('additional_fee', 10, 2)->default(0)->after('estimated_price');
            $table->decimal('discount', 10, 2)->default(0)->after('additional_fee');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['additional_fee', 'discount']);
        });
    }
};
