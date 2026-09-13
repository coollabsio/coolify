<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('instance_settings', 'is_ai_assistant_enabled')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->boolean('is_ai_assistant_enabled')->default(false);
            });
        }

        if (! Schema::hasColumn('teams', 'is_ai_assistant_enabled')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->boolean('is_ai_assistant_enabled')->default(true);
            });
        }

        if (! Schema::hasTable('ai_provider_credentials')) {
            Schema::create('ai_provider_credentials', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique(); // BaseModel auto-generates uuid on create
                $table->foreignId('team_id')->constrained()->onDelete('cascade');
                $table->string('provider');
                $table->string('model');
                $table->text('api_key');
                $table->string('base_url')->nullable();
                $table->boolean('is_default')->default(false);
                $table->boolean('enabled')->default(true);
                $table->timestamps();

                $table->index(['team_id', 'provider']);
            });
        }

        if (! Schema::hasTable('agent_conversations')) {
            Schema::create('agent_conversations', function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('participant_type')->nullable();
                $table->unsignedBigInteger('participant_id')->nullable();
                $table->string('title');
                $table->timestamps();

                $table->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
            });
        }

        if (! Schema::hasTable('agent_conversation_messages')) {
            Schema::create('agent_conversation_messages', function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('conversation_id', 36)->index();
                $table->string('participant_type')->nullable();
                $table->unsignedBigInteger('participant_id')->nullable();
                $table->string('agent');
                $table->string('role', 25);
                $table->text('content');
                $table->text('attachments');
                $table->text('tool_calls');
                $table->text('tool_results');
                $table->text('usage');
                $table->text('meta');
                $table->text('approval_state')->nullable();
                $table->unsignedBigInteger('author_user_id')->nullable()->index();
                $table->timestamps();

                $table->index(['conversation_id', 'participant_type', 'participant_id', 'updated_at'], 'conversation_index');
                $table->index(['participant_type', 'participant_id'], 'participant_index');
            });
        }

        if (! Schema::hasTable('ai_conversations')) {
            Schema::create('ai_conversations', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique(); // BaseModel auto-generates uuid on create
                $table->foreignId('team_id')->constrained()->onDelete('cascade');
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('title')->nullable();
                $table->string('visibility')->default('private');
                $table->timestamp('pinned_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->string('sdk_conversation_id', 36)->nullable()->index();
                $table->string('default_provider')->nullable();
                $table->string('default_model')->nullable();
                $table->string('status')->default('idle');
                // Resolved tool-approval decisions, keyed by tool-call id:
                // ['decision' => 'approved'|'cancelled', 'reason' => '<action description>'].
                $table->json('decision_log')->nullable();
                $table->foreignId('responding_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['team_id', 'visibility']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('agent_conversation_messages');
        Schema::dropIfExists('agent_conversations');
        Schema::dropIfExists('ai_provider_credentials');

        if (Schema::hasColumn('teams', 'is_ai_assistant_enabled')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->dropColumn('is_ai_assistant_enabled');
            });
        }

        if (Schema::hasColumn('instance_settings', 'is_ai_assistant_enabled')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->dropColumn('is_ai_assistant_enabled');
            });
        }
    }
};
