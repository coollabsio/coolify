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
        Schema::create('scheduled_task_executions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->enum('status', ['success', 'failed', 'running'])->default('running');
            $table->longText('message')->nullable();
            $table->foreignId('scheduled_task_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_executions');
    }
};
