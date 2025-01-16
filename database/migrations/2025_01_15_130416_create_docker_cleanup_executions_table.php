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
        Schema::create('docker_cleanup_executions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->enum('status', ['success', 'failed', 'running'])->default('running');
            $table->text('message')->nullable();
            $table->json('cleanup_log')->nullable();
            $table->foreignId('server_id');
            $table->timestamps();
            $table->timestamp('finished_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('docker_cleanup_executions');
    }
};
