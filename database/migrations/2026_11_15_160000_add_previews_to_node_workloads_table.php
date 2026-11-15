<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Previews of pull requests (decision 0025): a preview is a workload with a parent, and the
 * parent keeps the settings of its previews.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            // Restrict: the delete of a parent removes its previews first, so no preview loses its containers.
            $table->foreignId('preview_of_id')->nullable()->constrained('node_workloads')->restrictOnDelete();
            $table->unsignedInteger('pull_request_id')->nullable();
            $table->string('pull_request_url', 2048)->nullable();
            $table->string('pull_request_ref')->nullable();
            $table->boolean('pull_request_from_fork')->default(false);
            $table->unsignedBigInteger('pull_request_comment_id')->nullable();
            $table->unique(['preview_of_id', 'pull_request_id']);

            $table->boolean('preview_deployments_enabled')->default(false);
            $table->boolean('fork_preview_deployments_enabled')->default(false);
            $table->string('preview_url_template')->default('{{pr_id}}.{{domain}}');
            $table->boolean('preview_uses_production_values')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->dropUnique(['preview_of_id', 'pull_request_id']);
            $table->dropConstrainedForeignId('preview_of_id');
            $table->dropColumn([
                'pull_request_id',
                'pull_request_url',
                'pull_request_ref',
                'pull_request_from_fork',
                'pull_request_comment_id',
                'preview_deployments_enabled',
                'fork_preview_deployments_enabled',
                'preview_url_template',
                'preview_uses_production_values',
            ]);
        });
    }
};
