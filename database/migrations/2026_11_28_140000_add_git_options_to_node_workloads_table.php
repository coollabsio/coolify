<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The v4 Git options and watch paths for cluster applications, and the Git options for Git stacks
 * (decision 0033). The defaults keep the builds of today: submodules, Git LFS, and a shallow fetch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->boolean('is_git_submodules_enabled')->default(true);
            $table->boolean('is_git_lfs_enabled')->default(true);
            $table->boolean('is_git_shallow_clone_enabled')->default(true);
            $table->text('watch_paths')->nullable();
        });
        Schema::table('node_stacks', function (Blueprint $table) {
            $table->boolean('is_git_submodules_enabled')->default(true);
            $table->boolean('is_git_lfs_enabled')->default(true);
            $table->boolean('is_git_shallow_clone_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('node_stacks', function (Blueprint $table) {
            $table->dropColumn(['is_git_submodules_enabled', 'is_git_lfs_enabled', 'is_git_shallow_clone_enabled']);
        });
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->dropColumn(['is_git_submodules_enabled', 'is_git_lfs_enabled', 'is_git_shallow_clone_enabled', 'watch_paths']);
        });
    }
};
