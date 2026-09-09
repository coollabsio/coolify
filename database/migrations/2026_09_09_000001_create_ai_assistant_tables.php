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
    }

    public function down(): void
    {
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
