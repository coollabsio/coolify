<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('ended_at')->nullable()->index()->after('stripe_refunded_at');
        });

        // updated_at is the best available estimate for rows that ended before this column existed.
        DB::table('subscriptions')
            ->where('stripe_invoice_paid', false)
            ->where('stripe_past_due', false)
            ->update(['ended_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['ended_at']);
            $table->dropColumn('ended_at');
        });
    }
};
