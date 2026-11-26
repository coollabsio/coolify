<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Maintenance mode: the proxy shows a maintenance page (status 503) instead of the resource.
     */
    public function up(): void
    {
        foreach (['applications', 'services'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->boolean('is_maintenance_enabled')->default(false);
                $table->text('maintenance_page')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['applications', 'services'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['is_maintenance_enabled', 'maintenance_page']);
            });
        }
    }
};
