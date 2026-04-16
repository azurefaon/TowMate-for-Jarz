<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'first_name')) {
                $table->string('first_name')->nullable();
            }

            if (! Schema::hasColumn('customers', 'middle_name')) {
                $table->string('middle_name')->nullable();
            }

            if (! Schema::hasColumn('customers', 'last_name')) {
                $table->string('last_name')->nullable();
            }

            if (! Schema::hasColumn('customers', 'customer_type')) {
                $table->string('customer_type')->default('regular');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'first_name')) {
                $table->string('first_name')->nullable();
            }

            if (! Schema::hasColumn('users', 'middle_name')) {
                $table->string('middle_name')->nullable();
            }

            if (! Schema::hasColumn('users', 'last_name')) {
                $table->string('last_name')->nullable();
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'additional_fee')) {
                $table->decimal('additional_fee', 10, 2)->default(0);
            }

            if (! Schema::hasColumn('bookings', 'remarks')) {
                $table->text('remarks')->nullable();
            }

            if (! Schema::hasColumn('bookings', 'confirmation_type')) {
                $table->string('confirmation_type')->nullable()->default('system');
            }

            if (! Schema::hasColumn('bookings', 'customer_type')) {
                $table->string('customer_type')->default('regular');
            }

            if (! Schema::hasColumn('bookings', 'vehicle_image_path')) {
                $table->string('vehicle_image_path')->nullable();
            }
        });

        DB::table('customers')->orderBy('id')->get()->each(function ($customer) {
            $name = split_full_name($customer->full_name ?? '');

            DB::table('customers')
                ->where('id', $customer->id)
                ->update([
                    'first_name' => $customer->first_name ?: $name['first_name'],
                    'middle_name' => $customer->middle_name ?: $name['middle_name'],
                    'last_name' => $customer->last_name ?: $name['last_name'],
                    'customer_type' => $customer->customer_type ?: ($customer->is_pwd ? 'pwd' : ($customer->is_senior ? 'senior' : 'regular')),
                ]);
        });

        DB::table('users')->orderBy('id')->get()->each(function ($user) {
            $name = split_full_name($user->name ?? '');

            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'first_name' => $user->first_name ?: $name['first_name'],
                    'middle_name' => $user->middle_name ?: $name['middle_name'],
                    'last_name' => $user->last_name ?: $name['last_name'],
                ]);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            foreach (['additional_fee', 'remarks', 'confirmation_type', 'customer_type', 'vehicle_image_path'] as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            foreach (['first_name', 'middle_name', 'last_name', 'customer_type'] as $column) {
                if (Schema::hasColumn('customers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            foreach (['first_name', 'middle_name', 'last_name'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
