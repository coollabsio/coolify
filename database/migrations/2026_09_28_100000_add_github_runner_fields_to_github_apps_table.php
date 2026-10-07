<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores the GitHub Actions runner permissions, webhook events, and runner group of a GitHub App.
     */
    public function up(): void
    {
        Schema::table('github_apps', function (Blueprint $table) {
            $table->string('organization_self_hosted_runners')->nullable()->after('administration');
            $table->string('actions')->nullable()->after('organization_self_hosted_runners');
            $table->json('webhook_events')->nullable()->after('actions');
            $table->unsignedBigInteger('runner_group_id')->nullable()->after('webhook_events');
        });
    }

    public function down(): void
    {
        Schema::table('github_apps', function (Blueprint $table) {
            $table->dropColumn(['organization_self_hosted_runners', 'actions', 'webhook_events', 'runner_group_id']);
        });
    }
};
