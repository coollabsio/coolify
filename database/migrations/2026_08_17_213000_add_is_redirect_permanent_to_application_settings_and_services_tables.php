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
            $table->boolean('is_redirect_permanent')->default(false)->after('is_stripprefix_enabled');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->boolean('is_redirect_permanent')->default(false)->after('connect_to_docker_network');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('is_redirect_permanent');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('is_redirect_permanent');
        });
    }
};
