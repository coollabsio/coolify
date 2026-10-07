<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_runner_executions', function (Blueprint $table) {
            $table->boolean('is_pull_request')->default(false)->after('labels');
        });
    }

    public function down(): void
    {
        Schema::table('github_runner_executions', function (Blueprint $table) {
            $table->dropColumn('is_pull_request');
        });
    }
};
