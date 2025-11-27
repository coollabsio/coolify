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
        Schema::table('applications', function (Blueprint $table) {
            $table->integer('restart_count')->default(0)->after('status');
            $table->timestamp('last_restart_at')->nullable()->after('restart_count');
            $table->string('last_restart_type', 10)->nullable()->after('last_restart_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['restart_count', 'last_restart_at', 'last_restart_type']);
        });
    }
};
