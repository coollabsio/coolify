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
        if (! Schema::hasColumn('instance_settings', 'dev_helper_version')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->string('dev_helper_version')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('instance_settings', 'dev_helper_version')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->dropColumn('dev_helper_version');
            });
        }
    }
};
