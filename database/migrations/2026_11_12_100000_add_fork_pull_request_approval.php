<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fork pull request previews need an admin approval for each commit. The default `true` also
     * applies to applications that already deploy public (fork) pull requests.
     */
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('is_pr_deployments_public_approval_required')->default(true);
        });

        Schema::table('application_previews', function (Blueprint $table) {
            $table->boolean('is_fork_pull_request')->default(false);
            $table->string('pending_approval_commit')->nullable();
            $table->string('approved_commit')->nullable();
            $table->timestamp('approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('is_pr_deployments_public_approval_required');
        });

        Schema::table('application_previews', function (Blueprint $table) {
            $table->dropColumn(['is_fork_pull_request', 'pending_approval_commit', 'approved_commit', 'approved_at']);
        });
    }
};
