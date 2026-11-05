<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A scheduled task that keeps failing is disabled automatically. See ScheduledTask::recordRunFailure().
     */
    public function up(): void
    {
        if (Schema::hasColumn('scheduled_tasks', 'failure_count')) {
            return;
        }

        Schema::table('scheduled_tasks', function (Blueprint $table) {
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestampTz('failing_since')->nullable();
            $table->timestampTz('auto_disabled_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_tasks', function (Blueprint $table) {
            $table->dropColumn(['failure_count', 'failing_since', 'auto_disabled_at']);
        });
    }
};
