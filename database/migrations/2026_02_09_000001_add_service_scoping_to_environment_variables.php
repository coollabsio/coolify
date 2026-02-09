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
     * This migration adds service-level scoping to environment variables
     * to fix issue #7655 - Docker Compose semantic violations where all
     * services receive all environment variables.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('environment_variables', 'service_names')) {
            Schema::table('environment_variables', function (Blueprint $table) {
                // Array of service names this variable applies to
                // NULL or ['all'] means apply to all services (backward compatible)
                $table->json('service_names')->nullable()->after('value');
            });
        }

        if (! Schema::hasColumn('environment_variables', 'is_interpolation_only')) {
            Schema::table('environment_variables', function (Blueprint $table) {
                // Whether this variable is ONLY for ${VAR} interpolation in docker-compose.yml
                // If true, it goes in .env but NOT in runtime container environment
                $table->boolean('is_interpolation_only')->default(false)->after('is_buildtime');
            });
        }

        if (! Schema::hasColumn('environment_variables', 'injection_method')) {
            Schema::table('environment_variables', function (Blueprint $table) {
                // How to inject this variable: 'environment' (environment:), 'env_file' (env_file:), or 'none' (interpolation only)
                $table->enum('injection_method', ['none', 'environment', 'env_file'])->default('environment')->after('is_interpolation_only');
            });
        }

        // Migrate existing data: All existing variables default to 'environment' injection for ALL services
        // This preserves backward compatibility with existing behavior
        DB::table('environment_variables')
            ->whereNull('service_names')
            ->update([
                'service_names' => json_encode(['all']),
                'is_interpolation_only' => false,
                'injection_method' => 'environment',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('environment_variables', 'injection_method')) {
            Schema::table('environment_variables', function (Blueprint $table) {
                $table->dropColumn('injection_method');
            });
        }

        if (Schema::hasColumn('environment_variables', 'is_interpolation_only')) {
            Schema::table('environment_variables', function (Blueprint $table) {
                $table->dropColumn('is_interpolation_only');
            });
        }

        if (Schema::hasColumn('environment_variables', 'service_names')) {
            Schema::table('environment_variables', function (Blueprint $table) {
                $table->dropColumn('service_names');
            });
        }
    }
};
