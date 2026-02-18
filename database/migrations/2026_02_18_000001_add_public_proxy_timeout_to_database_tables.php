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
            'standalone_keydbs',
            'standalone_dragonflies',
            'standalone_mongodbs',
            'standalone_clickhouses',
            'service_databases',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->integer('public_proxy_timeout')->default(0)->after('public_port');
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
            'standalone_mysqls',
            'standalone_mariadbs',
            'standalone_redis',
            'standalone_keydbs',
            'standalone_dragonflies',
            'standalone_mongodbs',
            'standalone_clickhouses',
            'service_databases',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('public_proxy_timeout');
            });
        }
    }
};
