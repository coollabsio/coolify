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
        Schema::create('scheduled_volume_backups', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('backupable_type');
            $table->unsignedBigInteger('backupable_id');
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('s3_storage_id')->nullable()->constrained()->nullOnDelete();
            $table->string('frequency');
            $table->boolean('enabled')->default(true);
            $table->boolean('save_s3')->default(false);
            $table->boolean('disable_local_backup')->default(false);
            $table->boolean('stop_during_backup')->default(false);
            $table->unsignedInteger('retention_amount_locally')->default(7);
            $table->unsignedInteger('retention_days_locally')->default(0);
            $table->decimal('retention_max_storage_locally', 17, 7)->default(0);
            $table->unsignedInteger('retention_amount_s3')->default(7);
            $table->unsignedInteger('retention_days_s3')->default(0);
            $table->decimal('retention_max_storage_s3', 17, 7)->default(0);
            $table->unsignedInteger('timeout')->default(3600);
            $table->timestamps();

            $table->unique(['backupable_type', 'backupable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_volume_backups');
    }
};
