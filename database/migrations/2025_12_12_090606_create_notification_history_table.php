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
        Schema::create('notification_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('notification_type'); // Full class name of the notification
            $table->string('event_type'); // e.g., deployment_success, deployment_failure, etc.
            $table->string('channel'); // email, discord, telegram, slack, pushover, webhook
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable(); // Additional data like application info, deployment info, etc.
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'created_at']);
            $table->index(['team_id', 'event_type']);
            $table->index(['team_id', 'channel']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_history');
    }
};
