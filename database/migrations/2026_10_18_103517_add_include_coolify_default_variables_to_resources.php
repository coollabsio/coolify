<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'application_settings',
        'services',
        'standalone_postgresqls',
        'standalone_mysqls',
        'standalone_mariadbs',
        'standalone_mongodbs',
        'standalone_redis',
        'standalone_keydbs',
        'standalone_dragonflies',
        'standalone_clickhouses',
        'standalone_sqlites',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->boolean('include_coolify_default_variables')->default(true);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('include_coolify_default_variables');
            });
        }
    }
};
