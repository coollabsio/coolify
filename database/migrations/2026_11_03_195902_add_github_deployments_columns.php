<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_apps', function (Blueprint $table) {
            $table->string('deployments')->nullable()->after('pull_requests');
        });

        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->unsignedBigInteger('github_deployment_id')->nullable();
            $table->string('github_deployment_state')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('github_apps', function (Blueprint $table) {
            $table->dropColumn('deployments');
        });

        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->dropColumn(['github_deployment_id', 'github_deployment_state']);
        });
    }
};
