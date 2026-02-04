<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the permissions reference table with seeded values
     * for the granular permission system.
     */
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description', 255)->nullable();
            $table->string('resource_type', 50);
            $table->timestamps();

            $table->index('resource_type');
        });

        // Seed default permissions
        $permissions = [
            // Project permissions
            ['name' => 'project.view', 'description' => 'View project and resources', 'resource_type' => 'project'],
            ['name' => 'project.deploy', 'description' => 'Trigger deployments', 'resource_type' => 'project'],
            ['name' => 'project.manage', 'description' => 'Modify project settings', 'resource_type' => 'project'],
            ['name' => 'project.delete', 'description' => 'Delete project and resources', 'resource_type' => 'project'],

            // Environment permissions
            ['name' => 'environment.view', 'description' => 'View environment', 'resource_type' => 'environment'],
            ['name' => 'environment.deploy', 'description' => 'Deploy to environment', 'resource_type' => 'environment'],
            ['name' => 'environment.secrets', 'description' => 'View/edit environment variables', 'resource_type' => 'environment'],

            // Server permissions
            ['name' => 'server.view', 'description' => 'View server details', 'resource_type' => 'server'],
            ['name' => 'server.terminal', 'description' => 'Access server terminal', 'resource_type' => 'server'],
            ['name' => 'server.manage', 'description' => 'Manage server settings', 'resource_type' => 'server'],

            // Service permissions
            ['name' => 'service.view', 'description' => 'View service details', 'resource_type' => 'service'],
            ['name' => 'service.deploy', 'description' => 'Deploy service', 'resource_type' => 'service'],
            ['name' => 'service.manage', 'description' => 'Manage service settings', 'resource_type' => 'service'],
            ['name' => 'service.delete', 'description' => 'Delete service', 'resource_type' => 'service'],

            // Database permissions
            ['name' => 'database.view', 'description' => 'View database details', 'resource_type' => 'database'],
            ['name' => 'database.manage', 'description' => 'Manage database settings', 'resource_type' => 'database'],
            ['name' => 'database.delete', 'description' => 'Delete database', 'resource_type' => 'database'],

            // Application permissions
            ['name' => 'application.view', 'description' => 'View application details', 'resource_type' => 'application'],
            ['name' => 'application.deploy', 'description' => 'Deploy application', 'resource_type' => 'application'],
            ['name' => 'application.manage', 'description' => 'Manage application settings', 'resource_type' => 'application'],
            ['name' => 'application.delete', 'description' => 'Delete application', 'resource_type' => 'application'],
        ];

        $now = now();
        foreach ($permissions as &$permission) {
            $permission['created_at'] = $now;
            $permission['updated_at'] = $now;
        }

        DB::table('permissions')->insert($permissions);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
