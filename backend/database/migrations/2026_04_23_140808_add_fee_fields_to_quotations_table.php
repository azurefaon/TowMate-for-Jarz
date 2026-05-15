<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('quotations', function (Blueprint $table) {

            if (!Schema::hasColumn('quotations', 'additional_fee')) {
                $table->decimal('additional_fee', 10, 2)->default(0);
            }

            if (!Schema::hasColumn('quotations', 'discount')) {
                $table->decimal('discount', 10, 2)->default(0);
            }
        });
    }

    public function down()
    {
        Schema::table('quotations', function (Blueprint $table) {

            if (Schema::hasColumn('quotations', 'additional_fee')) {
                $table->dropColumn('additional_fee');
            }

            if (Schema::hasColumn('quotations', 'discount')) {
                $table->dropColumn('discount');
            }
        });
    }
};
