<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('application_settings', 'max_preview_deployments')) {
            Schema::table('application_settings', function (Blueprint $table) {
                $table->integer('max_preview_deployments')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('application_settings', 'max_preview_deployments')) {
            Schema::table('application_settings', function (Blueprint $table) {
                $table->dropColumn('max_preview_deployments');
            });
        }
    }
};
