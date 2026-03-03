<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('github_runner_configs', function (Blueprint $table) {
            $table->unsignedInteger('capacity_wait_timeout')->default(60)->after('max_runners');
        });
    }

    public function down(): void
    {
        Schema::table('github_runner_configs', function (Blueprint $table) {
            $table->dropColumn('capacity_wait_timeout');
        });
    }
};
