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
            $table->longText('fqdn')->nullable()->change();
        });
        Schema::table('application_previews', function (Blueprint $table) {
            $table->longText('fqdn')->nullable()->change();
        });
        Schema::table('service_applications', function (Blueprint $table) {
            $table->longText('fqdn')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('fqdn')->nullable()->change();
        });
        Schema::table('application_previews', function (Blueprint $table) {
            $table->string('fqdn')->nullable()->change();
        });
        Schema::table('service_applications', function (Blueprint $table) {
            $table->string('fqdn')->nullable()->change();
        });
    }
};
