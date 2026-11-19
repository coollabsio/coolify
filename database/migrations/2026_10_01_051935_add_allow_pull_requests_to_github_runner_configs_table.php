<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Runners refuse pull request jobs unless this is enabled.
     */
    public function up(): void
    {
        Schema::table('github_runner_configs', function (Blueprint $table) {
            $table->boolean('allow_pull_requests')->default(false)->after('is_dedicated');
        });
    }

    public function down(): void
    {
        Schema::table('github_runner_configs', function (Blueprint $table) {
            $table->dropColumn('allow_pull_requests');
        });
    }
};
