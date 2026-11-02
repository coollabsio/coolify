<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->boolean('is_env_sorting_enabled')->default(false);
            $table->boolean('include_coolify_default_variables')->default(true);
            $table->boolean('redact_all_env_values_in_logs')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->dropColumn(['is_env_sorting_enabled', 'include_coolify_default_variables', 'redact_all_env_values_in_logs']);
        });
    }
};
