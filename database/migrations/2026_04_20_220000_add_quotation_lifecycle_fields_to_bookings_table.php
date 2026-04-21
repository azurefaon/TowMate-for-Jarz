<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'quotation_expires_at')) {
                $table->timestamp('quotation_expires_at')->nullable()->after('quotation_sent_at');
            }

            if (! Schema::hasColumn('bookings', 'quotation_follow_up_sent_at')) {
                $table->timestamp('quotation_follow_up_sent_at')->nullable()->after('quotation_expires_at');
            }
        });

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::table('bookings')
                ->whereNotNull('quotation_sent_at')
                ->whereNull('quotation_expires_at')
                ->update([
                    'quotation_expires_at' => DB::raw("DATE_ADD(quotation_sent_at, INTERVAL 7 DAY)"),
                ]);

            return;
        }

        DB::table('bookings')
            ->whereNotNull('quotation_sent_at')
            ->whereNull('quotation_expires_at')
            ->select(['id', 'quotation_sent_at'])
            ->orderBy('id')
            ->get()
            ->each(function ($booking) {
                DB::table('bookings')
                    ->where('id', $booking->id)
                    ->update([
                        'quotation_expires_at' => Carbon::parse($booking->quotation_sent_at)->addDays(7),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $dropColumns = [];

            if (Schema::hasColumn('bookings', 'quotation_expires_at')) {
                $dropColumns[] = 'quotation_expires_at';
            }

            if (Schema::hasColumn('bookings', 'quotation_follow_up_sent_at')) {
                $dropColumns[] = 'quotation_follow_up_sent_at';
            }

            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
