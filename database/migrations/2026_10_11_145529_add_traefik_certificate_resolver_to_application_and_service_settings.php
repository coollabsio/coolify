<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-resource Traefik certificate resolver: null uses the server setting, "none" turns it off.
     */
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->string('traefik_certificate_resolver')->nullable();
        });
        Schema::table('service_applications', function (Blueprint $table) {
            $table->string('traefik_certificate_resolver')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('traefik_certificate_resolver');
        });
        Schema::table('service_applications', function (Blueprint $table) {
            $table->dropColumn('traefik_certificate_resolver');
        });
    }
};
