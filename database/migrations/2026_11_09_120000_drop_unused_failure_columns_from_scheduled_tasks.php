<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Instances that ran a removed migration of an earlier version of task auto-disable still have these columns.
     * The current version uses consecutive_failures, failing_since, and disabled_reason.
     */
    public function up(): void
    {
        foreach (['failure_count', 'auto_disabled_at'] as $column) {
            if (Schema::hasColumn('scheduled_tasks', $column)) {
                Schema::table('scheduled_tasks', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    public function down(): void
    {
        //
    }
};
