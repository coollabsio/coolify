<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_runner_executions', function (Blueprint $table) {
            $table->string('workflow_job_html_url')->nullable()->after('workflow_job_id');
        });
    }

    public function down(): void
    {
        Schema::table('github_runner_executions', function (Blueprint $table) {
            $table->dropColumn('workflow_job_html_url');
        });
    }
};
