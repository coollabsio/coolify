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
        Schema::table('additional_destinations', function (Blueprint $table) {
            $table->unique(
                ['application_id', 'server_id', 'standalone_docker_id'],
                'additional_destinations_application_server_docker_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('additional_destinations', function (Blueprint $table) {
            $table->dropUnique('additional_destinations_application_server_docker_unique');
        });
    }
};
