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
        Schema::table('scheduled_task_executions', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('scheduled_task_id');
            $table->integer('retry_count')->default(0)->after('status');
            $table->decimal('duration', 10, 2)->nullable()->after('retry_count')->comment('Duration in seconds');
            $table->text('error_details')->nullable()->after('message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_task_executions', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'retry_count', 'duration', 'error_details']);
        });
    }
};
