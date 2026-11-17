<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Markers for the deploy notifications of cluster resources, like v4 DeploymentSuccess and
     * DeploymentFailed. A deploy of a user, a rollback, or a deploy after a build sets the same
     * `notification_group` on its operation of each server. The first worker that sees every
     * operation of the group at an end status sets `notified_at` on all of them and sends the one
     * notification. A stack sets `deploy_notified_at` once for each deploy.
     */
    public function up(): void
    {
        Schema::table('node_operations', function (Blueprint $table) {
            $table->string('notification_group')->nullable()->index();
            $table->timestamp('notified_at')->nullable();
        });
        Schema::table('node_stacks', function (Blueprint $table) {
            $table->timestamp('deploy_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('node_operations', function (Blueprint $table) {
            $table->dropIndex(['notification_group']);
            $table->dropColumn(['notification_group', 'notified_at']);
        });
        Schema::table('node_stacks', function (Blueprint $table) {
            $table->dropColumn('deploy_notified_at');
        });
    }
};
