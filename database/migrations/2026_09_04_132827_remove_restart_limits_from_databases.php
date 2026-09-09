<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STANDALONE_DATABASE_TABLES = [
        'standalone_postgresqls',
        'standalone_redis',
        'standalone_mongodbs',
        'standalone_mysqls',
        'standalone_mariadbs',
        'standalone_keydbs',
        'standalone_dragonflies',
        'standalone_clickhouses',
    ];

    public function up(): void
    {
        foreach (self::STANDALONE_DATABASE_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['max_restart_count', 'restart_limit_reached']);
            });
        }

        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropColumn([
                'restart_count',
                'max_restart_count',
                'restart_limit_reached',
                'last_restart_at',
                'last_restart_type',
            ]);
        });
    }

    public function down(): void
    {
        foreach (self::STANDALONE_DATABASE_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->integer('max_restart_count')->default(10);
                $table->boolean('restart_limit_reached')->default(false);
            });
        }

        Schema::table('service_databases', function (Blueprint $table) {
            $table->integer('restart_count')->default(0);
            $table->integer('max_restart_count')->default(10);
            $table->boolean('restart_limit_reached')->default(false);
            $table->timestamp('last_restart_at')->nullable();
            $table->string('last_restart_type', 10)->nullable();
        });
    }
};
