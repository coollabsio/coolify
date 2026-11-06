<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A nightly build of `next` added `failing_since` in an earlier version of this feature.
        Schema::table('scheduled_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('scheduled_tasks', 'consecutive_failures')) {
                $table->unsignedInteger('consecutive_failures')->default(0);
            }
            if (! Schema::hasColumn('scheduled_tasks', 'failing_since')) {
                $table->timestamp('failing_since')->nullable();
            }
            if (! Schema::hasColumn('scheduled_tasks', 'disabled_reason')) {
                $table->text('disabled_reason')->nullable();
            }
        });

        $this->setExecutionStatuses(['success', 'failed', 'running', 'skipped']);
    }

    public function down(): void
    {
        DB::table('scheduled_task_executions')->where('status', 'skipped')->update(['status' => 'success']);
        $this->setExecutionStatuses(['success', 'failed', 'running']);

        Schema::table('scheduled_tasks', function (Blueprint $table) {
            $table->dropColumn(['consecutive_failures', 'failing_since', 'disabled_reason']);
        });
    }

    /**
     * @param  list<string>  $statuses
     */
    private function setExecutionStatuses(array $statuses): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $allowed = implode(', ', array_map(fn (string $status) => "'{$status}'", $statuses));
            DB::statement('ALTER TABLE scheduled_task_executions DROP CONSTRAINT IF EXISTS scheduled_task_executions_status_check');
            DB::statement("ALTER TABLE scheduled_task_executions ADD CONSTRAINT scheduled_task_executions_status_check CHECK (status IN ({$allowed}))");

            return;
        }

        Schema::table('scheduled_task_executions', function (Blueprint $table) use ($statuses) {
            $table->enum('status', $statuses)->default('running')->change();
        });
    }
};
