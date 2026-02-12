<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'standalone_postgresqls',
            'standalone_mysqls',
            'standalone_mariadbs',
            'standalone_mongodbs',
            'standalone_redises',
            'standalone_keydbs',
            'standalone_dragonflies',
            'standalone_clickhouses',
            'service_databases',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table) {
                    if (!Schema::hasColumn($table->getTable(), 'proxy_timeout')) {
                        $table->integer('proxy_timeout')->default(300);
                    }
                });
            }
        }
    }

    public function down(): void
    {
        $tables = [
            'standalone_postgresqls',
            'standalone_mysqls',
            'standalone_mariadbs',
            'standalone_mongodbs',
            'standalone_redises',
            'standalone_keydbs',
            'standalone_dragonflies',
            'standalone_clickhouses',
            'service_databases',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table) {
                    if (Schema::hasColumn($table->getTable(), 'proxy_timeout')) {
                        $table->dropColumn('proxy_timeout');
                    }
                });
            }
        }
    }
};
