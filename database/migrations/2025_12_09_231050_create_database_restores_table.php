<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_restores', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();

            // Polymorphic relationship to the database being restored
            $table->morphs('database');

            // Reference to the backup execution being restored from
            $table->foreignId('scheduled_database_backup_execution_id')
                ->nullable()
                ->constrained('scheduled_database_backup_executions')
                ->nullOnDelete();

            // Restore engine and target
            $table->string('engine')->default('pgbackrest');
            $table->string('target_label')->nullable();
            $table->timestamp('target_time')->nullable();

            // Status tracking: pending, running, success, failed
            $table->string('status')->default('pending');
            $table->longText('message')->nullable();
            $table->longText('log')->nullable();

            $table->timestamps();
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_restores');
    }
};
