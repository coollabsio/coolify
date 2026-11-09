<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'applications', 'services', 'standalone_postgresqls', 'standalone_mysqls', 'standalone_mariadbs',
        'standalone_mongodbs', 'standalone_redis', 'standalone_keydbs', 'standalone_dragonflies',
        'standalone_clickhouses', 'standalone_sqlites',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('config_key', 63)->nullable();
                $blueprint->unique(['environment_id', 'config_key']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropUnique(['environment_id', 'config_key']);
                $blueprint->dropColumn('config_key');
            });
        }
    }
};
