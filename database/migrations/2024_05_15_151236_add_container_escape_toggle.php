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
            $table->boolean('is_container_label_escape_enabled')->default(true);
        });
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('is_container_label_escape_enabled')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('is_container_label_escape_enabled');
        });
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('is_container_label_escape_enabled');
        });
    }
};
