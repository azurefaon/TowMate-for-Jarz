<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'risk_level')) {
                $table->string('risk_level')->nullable()->after('customer_type');
            }

            if (! Schema::hasColumn('customers', 'risk_reason')) {
                $table->text('risk_reason')->nullable()->after('risk_level');
            }

            if (! Schema::hasColumn('customers', 'blacklisted_at')) {
                $table->timestamp('blacklisted_at')->nullable()->after('risk_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $dropColumns = [];

            if (Schema::hasColumn('customers', 'risk_level')) {
                $dropColumns[] = 'risk_level';
            }

            if (Schema::hasColumn('customers', 'risk_reason')) {
                $dropColumns[] = 'risk_reason';
            }

            if (Schema::hasColumn('customers', 'blacklisted_at')) {
                $dropColumns[] = 'blacklisted_at';
            }

            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
