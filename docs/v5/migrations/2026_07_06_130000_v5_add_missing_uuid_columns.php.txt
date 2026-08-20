<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The uuid columns were added by editing the original v5 create migrations,
 * so databases that ran those migrations before the edit (including the
 * checked-in testing schema dump) are missing them. Guarded so freshly
 * migrated databases are untouched.
 */
return new class extends Migration
{
    private const TABLES = ['v5_applications', 'v5_clusters', 'v5_resource_connections'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'uuid')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->string('uuid')->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        // Intentionally left empty: the uuid columns belong to the create
        // migrations; dropping them here could destroy live identifiers.
    }
};
