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
        Schema::table('application_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('application_settings', 'is_preview_auto_deploy_enabled')) {
                $table->boolean('is_preview_auto_deploy_enabled')->default(true)->after('is_pr_deployments_public_enabled');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            if (Schema::hasColumn('application_settings', 'is_preview_auto_deploy_enabled')) {
                $table->dropColumn('is_preview_auto_deploy_enabled');
            }
        });
    }
};
