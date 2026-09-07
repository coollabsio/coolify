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
        Schema::create('v5_applications', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained('environments')->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained('v5_servers')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('image');
            $table->string('container_name')->unique();
            $table->string('status')->default('creating');
            $table->text('status_message')->nullable();
            $table->string('runtime_container_id')->nullable();
            $table->string('mesh_namespace')->default('default');
            $table->boolean('ingress_enabled')->default(false);
            $table->unsignedSmallInteger('internal_port')->nullable();
            $table->integer('canvas_x')->default(0);
            $table->integer('canvas_y')->default(0);
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'project_id', 'environment_id']);
            $table->index(['team_id', 'server_id']);
        });

        Schema::create('v5_application_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('v5_applications')->cascadeOnDelete();
            $table->string('domain');
            $table->timestamps();

            $table->unique(['application_id', 'domain']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('v5_application_domains');
        Schema::dropIfExists('v5_applications');
    }
};
