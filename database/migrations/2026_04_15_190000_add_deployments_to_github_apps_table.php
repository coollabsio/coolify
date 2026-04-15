<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_apps', function (Blueprint $table) {
            $table->string('deployments')->nullable()->after('pull_requests');
        });
    }

    public function down(): void
    {
        Schema::table('github_apps', function (Blueprint $table) {
            $table->dropColumn('deployments');
        });
    }
};
