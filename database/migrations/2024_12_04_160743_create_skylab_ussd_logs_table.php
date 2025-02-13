<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('skylab_ussd_logs', function (Blueprint $table) {
            $table->id();
            $table->string('session_id');
            $table->string('msisdn');
            $table->string('service_code');
            $table->text('request_data');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('skylab_ussd_logs');
    }
};
