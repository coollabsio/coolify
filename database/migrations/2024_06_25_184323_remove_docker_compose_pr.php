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
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('docker_compose_pr_location');
            $table->dropColumn('docker_compose_pr');
            $table->dropColumn('docker_compose_pr_raw');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('docker_compose_pr_location')->nullable()->default('/docker-compose.yaml')->after('docker_compose_location');
            $table->longText('docker_compose_pr')->nullable()->after('docker_compose_location');
            $table->longText('docker_compose_pr_raw')->nullable()->after('docker_compose');
        });
    }
};
