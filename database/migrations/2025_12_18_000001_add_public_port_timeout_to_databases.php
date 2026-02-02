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
        // Add public_port_timeout to all standalone database tables
        // Default is 0 which means no timeout (infinite)
        $tables = [
            'standalone_postgresqls',
            'standalone_mysqls',
            'standalone_mariadbs',
            'standalone_redis',
            'standalone_mongodbs',
            'standalone_keydbs',
            'standalone_dragonflies',
            'standalone_clickhouses',
            'service_databases',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'public_port_timeout')) {
                Schema::table($table, function (Blueprint $table) {
                    // Timeout in seconds. 0 = no timeout (infinite), default is 0
                    $table->integer('public_port_timeout')->default(0)->after('public_port');
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
            'standalone_redis',
            'standalone_mongodbs',
            'standalone_keydbs',
            'standalone_dragonflies',
            'standalone_clickhouses',
            'service_databases',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'public_port_timeout')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn('public_port_timeout');
                });
            }
        }
    }
};
