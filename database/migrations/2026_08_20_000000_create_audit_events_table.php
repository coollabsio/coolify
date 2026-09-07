<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('event');
            $table->string('source', 32);
            $table->string('action', 64);
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->unsignedBigInteger('actor_token_id')->nullable();
            $table->string('actor_token_name')->nullable();
            $table->string('resource_type')->nullable();
            $table->string('resource_uuid')->nullable();
            $table->string('resource_name')->nullable();
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 200)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['team_id', 'created_at', 'id']);
            $table->index(['team_id', 'action', 'created_at', 'id']);
            $table->index(['team_id', 'source', 'created_at', 'id']);
            $table->index(['team_id', 'resource_type', 'resource_uuid', 'created_at']);
            $table->index(['team_id', 'actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
