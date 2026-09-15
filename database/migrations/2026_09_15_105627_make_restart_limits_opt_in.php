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
        foreach (['applications', 'application_previews', 'service_applications'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->integer('max_restart_count')->default(0)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['applications', 'application_previews', 'service_applications'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->integer('max_restart_count')->default(10)->change();
            });
        }
    }
};
