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
        $tables = [
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

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table) {
                    $table->integer('proxy_timeout')->default(1800)->after('public_port'); // 30 minutes default
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
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

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'proxy_timeout')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn('proxy_timeout');
                });
            }
        }
    }
};