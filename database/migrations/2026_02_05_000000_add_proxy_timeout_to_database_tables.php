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
            'standalone_redis',
            'standalone_mongodbs',
            'standalone_mysqls',
            'standalone_mariadbs',
            'standalone_keydbs',
            'standalone_dragonflies',
            'standalone_clickhouses',
            'service_databases',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                // proxy_timeout in seconds, 0 = no timeout (default for long-running queries)
                $table->integer('proxy_timeout')->default(0)->after('public_port');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'standalone_postgresqls',
            'standalone_redis',
            'standalone_mongodbs',
            'standalone_mysqls',
            'standalone_mariadbs',
            'standalone_keydbs',
            'standalone_dragonflies',
            'standalone_clickhouses',
            'service_databases',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('proxy_timeout');
            });
        }
    }
};
