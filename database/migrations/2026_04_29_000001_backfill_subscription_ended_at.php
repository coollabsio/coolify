<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Refunded subscriptions
        DB::table('subscriptions')
            ->whereNotNull('stripe_refunded_at')
            ->whereNull('ended_at')
            ->update(['ended_at' => DB::raw('stripe_refunded_at')]);

        // Unpaid subscriptions
        DB::table('subscriptions')
            ->where('stripe_invoice_paid', false)
            ->whereNull('ended_at')
            ->update(['ended_at' => DB::raw('updated_at')]);
    }
};
