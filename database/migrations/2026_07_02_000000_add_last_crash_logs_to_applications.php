<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $blueprint) {
            $blueprint->json('last_crash_logs')->nullable()->after('max_restart_count');
            $blueprint->timestamp('last_crash_logs_captured_at')->nullable()->after('last_crash_logs');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $blueprint) {
            $blueprint->dropColumn(['last_crash_logs', 'last_crash_logs_captured_at']);
        });
    }
};
