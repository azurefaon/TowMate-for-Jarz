<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bookings') && ! Schema::hasColumn('bookings', 'booking_code')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('booking_code', 7)->nullable()->unique()->after('id');
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'user_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('user_code', 7)->nullable()->unique()->after('id');
            });
        }

        if (Schema::hasTable('receipts') && ! Schema::hasColumn('receipts', 'receipt_code')) {
            Schema::table('receipts', function (Blueprint $table) {
                $table->string('receipt_code', 7)->nullable()->unique()->after('id');
            });
        }

        $this->backfillCodes('users', 'user_code');
        $this->backfillCodes('bookings', 'booking_code');
        $this->backfillCodes('receipts', 'receipt_code');
    }

    public function down(): void
    {
        if (Schema::hasTable('receipts') && Schema::hasColumn('receipts', 'receipt_code')) {
            Schema::table('receipts', function (Blueprint $table) {
                $table->dropUnique(['receipt_code']);
                $table->dropColumn('receipt_code');
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'user_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['user_code']);
                $table->dropColumn('user_code');
            });
        }

        if (Schema::hasTable('bookings') && Schema::hasColumn('bookings', 'booking_code')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropUnique(['booking_code']);
                $table->dropColumn('booking_code');
            });
        }
    }

    protected function backfillCodes(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $rows = DB::table($table)->select('id', $column)->orderBy('id')->get();
        $nextNumber = 1;

        foreach ($rows as $row) {
            $existingNumber = (int) preg_replace('/\D+/', '', (string) ($row->{$column} ?? ''));
            $number = $existingNumber > 0 ? max($existingNumber, $nextNumber) : $nextNumber;
            $code = str_pad((string) $number, 7, '0', STR_PAD_LEFT);

            while (DB::table($table)->where($column, $code)->where('id', '!=', $row->id)->exists()) {
                $number++;
                $code = str_pad((string) $number, 7, '0', STR_PAD_LEFT);
            }

            DB::table($table)->where('id', $row->id)->update([$column => $code]);
            $nextNumber = $number + 1;
        }
    }
};
