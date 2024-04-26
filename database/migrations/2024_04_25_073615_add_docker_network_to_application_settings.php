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
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('connect_to_docker_network')->default(false);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('connect_to_docker_network');
        });
    }
};
