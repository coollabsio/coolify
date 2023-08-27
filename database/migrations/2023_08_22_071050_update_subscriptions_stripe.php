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
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('stripe_invoice_paid')->default(false);
            $table->string('stripe_subscription_id')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->boolean('stripe_cancel_at_period_end')->default(false);
            $table->string('lemon_subscription_id')->nullable()->change();
            $table->string('lemon_order_id')->nullable()->change();
            $table->string('lemon_product_id')->nullable()->change();
            $table->string('lemon_variant_id')->nullable()->change();
            $table->string('lemon_variant_name')->nullable()->change();
            $table->string('lemon_customer_id')->nullable()->change();
            $table->string('lemon_status')->nullable()->change();
            $table->string('lemon_renews_at')->nullable()->change();
            $table->string('lemon_update_payment_menthod_url')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('stripe_invoice_paid');
            $table->dropColumn('stripe_subscription_id');
            $table->dropColumn('stripe_customer_id');
            $table->dropColumn('stripe_cancel_at_period_end');
            $table->string('lemon_subscription_id')->change();
            $table->string('lemon_order_id')->change();
            $table->string('lemon_product_id')->change();
            $table->string('lemon_variant_id')->change();
            $table->string('lemon_variant_name')->change();
            $table->string('lemon_customer_id')->change();
            $table->string('lemon_status')->change();
            $table->string('lemon_renews_at')->change();
            $table->string('lemon_update_payment_menthod_url')->change();
        });
    }
};
