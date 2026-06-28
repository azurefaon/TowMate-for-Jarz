<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->text('customer_inquiry')->nullable()->after('response_note');
            $table->timestamp('inquiry_sent_at')->nullable()->after('customer_inquiry');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['customer_inquiry', 'inquiry_sent_at']);
        });
    }
};
