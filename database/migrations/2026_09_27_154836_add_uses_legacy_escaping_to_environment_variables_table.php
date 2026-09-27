<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existing variables keep the old value escaping, so containers get the same values after the upgrade.
     * New variables, and variables that users save again, use exact escaping.
     */
    public function up(): void
    {
        if (Schema::hasColumn('environment_variables', 'uses_legacy_escaping')) {
            return;
        }

        Schema::table('environment_variables', function (Blueprint $table) {
            $table->boolean('uses_legacy_escaping')->default(true);
        });

        Schema::table('environment_variables', function (Blueprint $table) {
            $table->boolean('uses_legacy_escaping')->default(false)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('environment_variables', 'uses_legacy_escaping')) {
            return;
        }

        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn('uses_legacy_escaping');
        });
    }
};
