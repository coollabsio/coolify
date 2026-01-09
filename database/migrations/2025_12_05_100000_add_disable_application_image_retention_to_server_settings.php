<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('server_settings', 'disable_application_image_retention')) {
            Schema::table('server_settings', function (Blueprint $table) {
                $table->boolean('disable_application_image_retention')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('server_settings', 'disable_application_image_retention')) {
            Schema::table('server_settings', function (Blueprint $table) {
                $table->dropColumn('disable_application_image_retention');
            });
        }
    }
};
