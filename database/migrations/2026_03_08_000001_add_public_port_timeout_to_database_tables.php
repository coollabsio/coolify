<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'public_port_timeout')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->integer('public_port_timeout')->default(0)->after('public_port');
                });
            }
        }
    }

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
