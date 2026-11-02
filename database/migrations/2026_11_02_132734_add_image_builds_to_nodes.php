<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Image builds from Git repositories on builder Nodes, and the cluster
 * registry that stores the images (decision 0006).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->boolean('is_builder')->default(true);
        });

        Schema::table('node_workloads', function (Blueprint $table) {
            $table->string('source_type')->default('image');
            $table->string('git_repository', 2048)->nullable();
            $table->string('git_branch')->nullable();
            $table->string('base_directory', 512)->nullable();
            $table->string('dockerfile_location', 512)->nullable();
        });

        Schema::create('node_registries', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('node_cluster_id')->unique()->constrained()->cascadeOnDelete();
            // The Node that the user selected. Null means the first active Node.
            $table->foreignId('selected_node_id')->nullable()->constrained('nodes')->nullOnDelete();
            // The Node that runs the registry now.
            $table->foreignId('node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->text('ca_certificate_pem');
            $table->text('ca_private_key_pem');
            $table->text('certificate_pem')->nullable();
            $table->text('private_key_pem')->nullable();
            $table->timestamp('certificate_valid_until')->nullable();
            $table->text('push_password');
            $table->text('pull_password');
            // bcrypt lines of both users, made once so zot does not restart on every reconcile.
            $table->text('htpasswd');
            $table->unsignedSmallInteger('keep_tags')->default(5);
            $table->timestamps();
        });

        Schema::create('node_builds', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('node_workload_id')->constrained()->cascadeOnDelete();
            // The builder Node. Null until the build is scheduled.
            $table->foreignId('node_id')->nullable()->constrained('nodes')->nullOnDelete();
            // The revision that the build produced.
            $table->foreignId('node_workload_revision_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type');
            $table->string('git_repository', 2048);
            $table->string('git_branch');
            $table->string('base_directory', 512)->nullable();
            $table->string('dockerfile_location', 512)->nullable();
            $table->string('commit_sha', 64)->nullable();
            $table->string('image', 2048)->nullable();
            $table->string('image_digest', 71)->nullable();
            $table->string('status')->default('queued');
            $table->text('error')->nullable();
            // RFC 3339 time of the last log line read, for `container.logs` `since`.
            $table->string('log_cursor', 40)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['node_workload_id', 'status']);
            $table->index(['node_id', 'status']);
        });

        Schema::create('node_build_log_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_build_id')->constrained()->cascadeOnDelete();
            $table->timestamp('logged_at', 6);
            $table->text('line');

            $table->index(['node_build_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_build_log_lines');
        Schema::dropIfExists('node_builds');
        Schema::dropIfExists('node_registries');
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'git_repository', 'git_branch', 'base_directory', 'dockerfile_location']);
        });
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('is_builder');
        });
    }
};
