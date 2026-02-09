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
            'standalone_surrealdb',
            'service_databases',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table) {
                    $table->string('public_proxy_timeout')->default('600s');
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
            'standalone_redis',
            'standalone_mongodbs',
            'standalone_mysqls',
            'standalone_mariadbs',
            'standalone_keydbs',
            'standalone_dragonflies',
            'standalone_clickhouses',
            'standalone_surrealdb',
            'service_databases',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn('public_proxy_timeout');
                });
            }
        }
    }
};
