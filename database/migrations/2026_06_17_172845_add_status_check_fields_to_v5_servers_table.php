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
        Schema::table('v5_servers', function (Blueprint $table) {
            $table->string('last_status_check')->nullable()->after('last_bootstrapped_at');
            $table->text('last_status_output')->nullable()->after('last_status_check');
            $table->timestamp('last_status_checked_at')->nullable()->after('last_status_output');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v5_servers', function (Blueprint $table) {
            $table->dropColumn([
                'last_status_check',
                'last_status_output',
                'last_status_checked_at',
            ]);
        });
    }
};
