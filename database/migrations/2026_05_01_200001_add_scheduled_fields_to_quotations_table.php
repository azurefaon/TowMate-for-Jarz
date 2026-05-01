<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('quotations', 'scheduled_date')) {
                $table->date('scheduled_date')->nullable()->after('service_type');
            }
            if (! Schema::hasColumn('quotations', 'scheduled_time')) {
                $table->string('scheduled_time', 8)->nullable()->after('scheduled_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumnIfExists('scheduled_time');
            $table->dropColumnIfExists('scheduled_date');
        });
    }
};
