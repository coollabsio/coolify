<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fork pull request previews need an admin approval for each commit. The default `true` also
     * applies to applications that already deploy public (fork) pull requests.
     *
     * `is_fork_pull_request` is null when Coolify does not know the origin of the pull request:
     * previews that exist before this migration and previews that a user opens by hand. A webhook
     * event of the pull request sets it. Until then, each commit needs an approval.
     */
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('is_pr_deployments_public_approval_required')->default(true);
        });

        Schema::table('application_previews', function (Blueprint $table) {
            $table->boolean('is_fork_pull_request')->nullable();
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
