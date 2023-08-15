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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('lemon_subscription_id');
            $table->string('lemon_order_id');
            $table->string('lemon_product_id');
            $table->string('lemon_variant_id');
            $table->string('lemon_variant_name');
            $table->string('lemon_customer_id');
            $table->string('lemon_status');
            $table->string('lemon_trial_ends_at')->nullable();
            $table->string('lemon_renews_at');
            $table->string('lemon_ends_at')->nullable();
            $table->string('lemon_update_payment_menthod_url');
            $table->foreignId('team_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
