<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'standalone_postgresqls',
        'standalone_mysqls',
        'standalone_mariadbs',
        'standalone_mongodbs',
        'standalone_redis',
        'standalone_keydbs',
        'standalone_dragonflies',
        'standalone_clickhouses',
        'service_databases',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('public_host')->nullable()->after('public_port');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('public_host');
            });
        }
    }
};
