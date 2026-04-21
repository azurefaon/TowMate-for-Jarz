<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'age')) {
                $table->unsignedSmallInteger('age')->nullable()->after('full_name');
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'age')) {
                $table->unsignedSmallInteger('age')->nullable()->after('created_by_admin_id');
            }

            if (!Schema::hasColumn('bookings', 'notes')) {
                $table->text('notes')->nullable()->after('final_total');
            }

            if (!Schema::hasColumn('bookings', 'quotation_number')) {
                $table->string('quotation_number')->nullable()->after('final_total');
            }

            if (!Schema::hasColumn('bookings', 'quotation_generated')) {
                $table->boolean('quotation_generated')->default(false)->after('quotation_number');
            }

            if (!Schema::hasColumn('bookings', 'rejection_reason')) {
                $table->string('rejection_reason')->nullable()->after('quotation_generated');
            }
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE `bookings` MODIFY `status` ENUM('requested','accepted','assigned','on_job','rejected','completed','cancelled') NOT NULL DEFAULT 'requested'");
        }
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'rejection_reason')) {
                $table->dropColumn('rejection_reason');
            }
            if (Schema::hasColumn('bookings', 'quotation_generated')) {
                $table->dropColumn('quotation_generated');
            }
            if (Schema::hasColumn('bookings', 'quotation_number')) {
                $table->dropColumn('quotation_number');
            }
            if (Schema::hasColumn('bookings', 'notes')) {
                $table->dropColumn('notes');
            }
            if (Schema::hasColumn('bookings', 'age')) {
                $table->dropColumn('age');
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'age')) {
                $table->dropColumn('age');
            }
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE `bookings` MODIFY `status` ENUM('requested','assigned','on_job','completed','cancelled') NOT NULL DEFAULT 'requested'");
        }
    }
};
