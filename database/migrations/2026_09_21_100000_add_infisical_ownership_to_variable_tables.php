<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['environment_variables', 'shared_environment_variables'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->boolean('is_infisical_managed')->default(false);
                $table->string('infisical_path')->nullable();
                $table->index('is_infisical_managed');
            });
        }
    }

    public function down(): void
    {
        foreach (['environment_variables', 'shared_environment_variables'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['is_infisical_managed', 'infisical_path']);
            });
        }
    }
};
