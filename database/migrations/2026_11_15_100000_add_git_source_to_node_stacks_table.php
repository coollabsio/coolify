<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Compose application from Git on a cluster (decision 0023, step 2): the stack keeps its Git
 * source, and each service with `build:` builds with the Node builder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_stacks', function (Blueprint $table) {
            $table->string('git_repository', 2048)->nullable();
            $table->string('git_branch')->nullable();
            $table->foreignId('github_app_id')->nullable()->constrained('github_apps')->nullOnDelete();
            $table->foreignId('private_key_id')->nullable()->constrained('private_keys')->nullOnDelete();
            $table->string('base_directory', 512)->nullable();
            $table->string('compose_location', 512)->nullable();
            // The commit of the loaded Compose file, then of the last deploy.
            $table->string('commit_sha', 64)->nullable();
        });

        Schema::table('node_workloads', function (Blueprint $table) {
            $table->string('build_target')->nullable();
        });

        Schema::table('node_builds', function (Blueprint $table) {
            // A stack deploy builds every service first and deploys them in depends_on order itself.
            $table->boolean('skip_deploy')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('node_builds', function (Blueprint $table) {
            $table->dropColumn('skip_deploy');
        });
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->dropColumn('build_target');
        });
        Schema::table('node_stacks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('private_key_id');
            $table->dropConstrainedForeignId('github_app_id');
            $table->dropColumn(['git_repository', 'git_branch', 'base_directory', 'compose_location', 'commit_sha']);
        });
    }
};
