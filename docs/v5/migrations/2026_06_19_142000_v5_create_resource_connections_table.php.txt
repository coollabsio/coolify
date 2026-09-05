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
        Schema::create('v5_resource_connections', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained('environments')->cascadeOnDelete();
            $table->morphs('resource_one');
            $table->morphs('resource_two');
            $table->string('resource_pair_key');
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'resource_pair_key']);
            $table->index(['team_id', 'project_id', 'environment_id']);
        });

        Schema::create('v5_resource_connection_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('v5_resource_connections')->cascadeOnDelete();
            $table->morphs('source_resource');
            $table->morphs('target_resource');
            $table->string('protocol')->default('tcp');
            $table->unsignedSmallInteger('port');
            $table->timestamps();

            $table->unique([
                'connection_id',
                'source_resource_type',
                'source_resource_id',
                'target_resource_type',
                'target_resource_id',
                'protocol',
                'port',
            ], 'v5_resource_connection_rules_unique_direction_port');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('v5_resource_connection_rules');
        Schema::dropIfExists('v5_resource_connections');
    }
};
