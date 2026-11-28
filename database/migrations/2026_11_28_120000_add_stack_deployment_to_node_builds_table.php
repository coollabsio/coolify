<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stack deploy ends its job while the builds of its services wait for a builder (decision
 * 0035). The builds point to their stack deployment, so the end of the last build resumes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_builds', function (Blueprint $table) {
            $table->foreignId('node_stack_deployment_id')->nullable()->constrained('node_stack_deployments')->nullOnDelete();
        });
        Schema::table('node_stack_deployments', function (Blueprint $table) {
            // A push deploy keeps image services without a change running (DeployNodeStackJob::keepsRunningOnPush()).
            $table->boolean('is_push')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('node_stack_deployments', function (Blueprint $table) {
            $table->dropColumn('is_push');
        });
        Schema::table('node_builds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('node_stack_deployment_id');
        });
    }
};
