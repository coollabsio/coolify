<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The database tables to add public_port_timeout column to.
     */
    private array $tables = [
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

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'public_port_timeout')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->integer('public_port_timeout')->nullable()->default(3600)->after('public_port');
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
            if (Schema::hasColumn($table, 'public_port_timeout')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('public_port_timeout');
                });
            }
        }
    }
};