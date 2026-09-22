<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            if (Schema::hasColumn('quotations', 'vehicle_type_id')) {
                $table->dropForeign(['vehicle_type_id']);
            }
        });

        Schema::table('quotations', function (Blueprint $table) {
            $columns = array_filter(
                ['vehicle_type_id', 'customer_vehicle_type', 'customer_vehicle_category'],
                fn ($column) => Schema::hasColumn('quotations', $column)
            );

            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            $columns = array_filter(
                ['customer_vehicle_type', 'customer_vehicle_category'],
                fn ($column) => Schema::hasColumn('bookings', $column)
            );

            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $columns = array_filter(
                [
                    'password_request_status',
                    'password_requested_at',
                    'password_request_note',
                    'password_request_resolved_at',
                    'username',
                ],
                fn ($column) => Schema::hasColumn('users', $column)
            );

            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'password_request_status')) {
                $table->string('password_request_status')->default('none')->after('status');
            }
            if (! Schema::hasColumn('users', 'password_requested_at')) {
                $table->timestamp('password_requested_at')->nullable()->after('password_request_status');
            }
            if (! Schema::hasColumn('users', 'password_request_note')) {
                $table->text('password_request_note')->nullable()->after('password_requested_at');
            }
            if (! Schema::hasColumn('users', 'password_request_resolved_at')) {
                $table->timestamp('password_request_resolved_at')->nullable()->after('password_request_note');
            }
            if (! Schema::hasColumn('users', 'username')) {
                $table->string('username', 50)->nullable()->unique()->after('last_name');
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'customer_vehicle_type')) {
                $table->string('customer_vehicle_type')->nullable()->after('vehicle_type_id');
            }
            if (! Schema::hasColumn('bookings', 'customer_vehicle_category')) {
                $table->enum('customer_vehicle_category', ['2_wheeler', '4_wheeler', 'heavy_vehicle'])->nullable()->after('customer_vehicle_type');
            }
        });

        Schema::table('quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('quotations', 'vehicle_type_id')) {
                $table->foreignId('vehicle_type_id')->nullable()->after('truck_type_id')->constrained()->onDelete('set null');
            }
            if (! Schema::hasColumn('quotations', 'customer_vehicle_type')) {
                $table->string('customer_vehicle_type')->nullable()->after('vehicle_type_id');
            }
            if (! Schema::hasColumn('quotations', 'customer_vehicle_category')) {
                $table->enum('customer_vehicle_category', ['2_wheeler', '4_wheeler', 'heavy_vehicle'])->nullable()->after('customer_vehicle_type');
            }
        });
    }
};
