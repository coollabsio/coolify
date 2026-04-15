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
            'standalone_redis',
            'standalone_mongodbs',
            'standalone_clickhouses',
            'standalone_keydbs',
            'standalone_dragonflies',
            'service_databases',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'public_host')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->string('public_host')->nullable()->after('public_port_timeout');
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
            'standalone_clickhouses',
            'standalone_keydbs',
            'standalone_dragonflies',
            'service_databases',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'public_host')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn('public_host');
                });
            }
        }
    }
};
