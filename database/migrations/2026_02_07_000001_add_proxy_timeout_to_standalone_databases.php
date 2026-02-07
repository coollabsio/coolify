<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The standalone database tables to add proxy timeout column to.
     */
    private array $tables = [
        'standalone_postgresqls',
        'standalone_mysqls',
        'standalone_mariadbs',
        'standalone_redis',
        'standalone_mongodbs',
        'standalone_keydbs',
        'standalone_dragonflies',
        'standalone_clickhouses',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'proxy_timeout')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->integer('proxy_timeout')->default(600)->after('public_port');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'proxy_timeout')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('proxy_timeout');
                });
            }
        }
    }
};
