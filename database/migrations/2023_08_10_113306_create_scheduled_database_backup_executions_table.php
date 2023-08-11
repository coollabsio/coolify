<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->enum('status', ['success', 'failed', 'running'])->default('running');
            $table->longText('message')->nullable();
            $table->text('size')->nullable();
            $table->text('filename')->nullable();
            $table->foreignId('scheduled_database_backup_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_database_backup_executions');
    }
};
