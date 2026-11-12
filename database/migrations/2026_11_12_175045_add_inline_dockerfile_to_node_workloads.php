<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A cluster application can build a Dockerfile that the user pasted, without a Git
     * repository (v4 `applications.dockerfile`). A build keeps a copy, like its other source settings.
     */
    public function up(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->text('dockerfile')->nullable();
        });
        Schema::table('node_builds', function (Blueprint $table) {
            $table->text('dockerfile')->nullable();
            $table->string('git_repository', 2048)->nullable()->change();
            $table->string('git_branch')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('node_builds', function (Blueprint $table) {
            $table->dropColumn('dockerfile');
        });
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->dropColumn('dockerfile');
        });
    }
};
