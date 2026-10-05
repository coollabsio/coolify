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
        Schema::table('scheduled_volume_backup_executions', function (Blueprint $table) {
            $table->timestampTz('recovery_last_attempt_at')->nullable();
            $table->string('recovery_error', 32)->nullable();
            $table->boolean('recovery_needs_attention')->default(false);

            $table->index(
                ['stop_recovery_pending', 's3_cleanup_pending', 'recovery_needs_attention'],
                'scheduled_volume_executions_recovery_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_volume_backup_executions', function (Blueprint $table) {
            $table->dropIndex('scheduled_volume_executions_recovery_index');
            $table->dropColumn([
                'recovery_last_attempt_at',
                'recovery_error',
                'recovery_needs_attention',
            ]);
        });
    }
};
