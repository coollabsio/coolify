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
        if (! Schema::hasColumn('scheduled_task_executions', 'started_at')) {
            Schema::table('scheduled_task_executions', function (Blueprint $table) {
                $table->timestamp('started_at')->nullable()->after('scheduled_task_id');
            });
        }

        if (! Schema::hasColumn('scheduled_task_executions', 'retry_count')) {
            Schema::table('scheduled_task_executions', function (Blueprint $table) {
                $table->integer('retry_count')->default(0)->after('status');
            });
        }

        if (! Schema::hasColumn('scheduled_task_executions', 'duration')) {
            Schema::table('scheduled_task_executions', function (Blueprint $table) {
                $table->decimal('duration', 10, 2)->nullable()->after('retry_count')->comment('Duration in seconds');
            });
        }

        if (! Schema::hasColumn('scheduled_task_executions', 'error_details')) {
            Schema::table('scheduled_task_executions', function (Blueprint $table) {
                $table->text('error_details')->nullable()->after('message');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $columns = ['started_at', 'retry_count', 'duration', 'error_details'];
        foreach ($columns as $column) {
            if (Schema::hasColumn('scheduled_task_executions', $column)) {
                Schema::table('scheduled_task_executions', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
