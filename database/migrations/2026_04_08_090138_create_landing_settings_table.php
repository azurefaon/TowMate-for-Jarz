<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('landing_settings', function (Blueprint $table) {
            $table->id();

            $table->string('hero_image')->nullable();
            $table->string('about_image')->nullable();

            $table->string('portfolio_main')->nullable();
            $table->string('portfolio_1')->nullable();
            $table->string('portfolio_2')->nullable();
            $table->string('portfolio_3')->nullable();

            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_location')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landing_settings');
    }
};
