<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
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

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->integer('public_proxy_timeout')->default(0)->after('public_port');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('public_proxy_timeout');
            });
        }
    }
};
