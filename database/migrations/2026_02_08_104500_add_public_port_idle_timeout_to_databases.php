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
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'public_port_idle_timeout')) {
                Schema::table($table, function (Blueprint $table) {
                    // Timeout in seconds for public port proxy connections.
                    // 0 means no timeout (infinite), null uses nginx default (10 minutes).
                    // Default to 0 (no timeout) for long-running queries.
                    $table->integer('public_port_idle_timeout')->nullable()->default(0)->after('public_port');
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
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'public_port_idle_timeout')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn('public_port_idle_timeout');
                });
            }
        }
    }
};
