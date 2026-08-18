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
        Schema::create('scheduled_volume_backup_executions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('scheduled_volume_backup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('s3_storage_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['success', 'failed', 'running'])->default('running');
            $table->longText('message')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->text('filename')->nullable();
            $table->json('stop_container_ids')->nullable();
            $table->boolean('stop_recovery_pending')->default(false);
            $table->boolean('s3_cleanup_pending')->default(false);
            $table->timestamp('finished_at')->nullable();
            $table->boolean('local_storage_deleted')->default(false);
            $table->boolean('s3_storage_deleted')->default(false);
            $table->boolean('s3_uploaded')->nullable();
            $table->timestamps();

            $table->index(['scheduled_volume_backup_id', 'created_at'], 'scheduled_volume_executions_backup_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_volume_backup_executions');
    }
};
