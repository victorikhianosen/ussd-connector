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
        Schema::create('skylab_log_billings', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('telco');
            $table->string('action');
            $table->string('shortcode');
            $table->integer('product_id');
            $table->string('product_name');
            $table->string('product_identity');
            $table->string('product_type');
            $table->string('product_subscription_type');
            $table->string('product_status');
            $table->string('details_phone');
            $table->decimal('details_amount', 10, 2);  // Adjust decimal precision as needed
            $table->string('details_channel');
            $table->timestamp('details_date')->nullable();  // Allow NULL values
            $table->timestamp('details_expiry')->nullable();  // Allow NULL values
            $table->boolean('details_auto_renewal');
            $table->string('details_telco_status_code');
            $table->string('details_telco_ref');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('skylab_log_billings');
    }
};
