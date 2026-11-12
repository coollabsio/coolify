<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The v4 Git source of a cluster application built from a private repository: a GitHub App
     * or a deploy key. Both are empty for a public repository. `source_type` is the build pack.
     */
    public function up(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->foreignId('github_app_id')->nullable()->after('git_branch')->constrained('github_apps')->nullOnDelete();
            $table->foreignId('private_key_id')->nullable()->after('github_app_id')->constrained('private_keys')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('private_key_id');
            $table->dropConstrainedForeignId('github_app_id');
        });
    }
};
