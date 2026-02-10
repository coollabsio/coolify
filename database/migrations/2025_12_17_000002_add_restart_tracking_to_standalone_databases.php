<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The standalone database tables to add restart tracking columns to.
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
            if (! Schema::hasColumn($table, 'restart_count')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->integer('restart_count')->default(0)->after('status');
                });
            }

            if (! Schema::hasColumn($table, 'last_restart_at')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->timestamp('last_restart_at')->nullable()->after('restart_count');
                });
            }

            if (! Schema::hasColumn($table, 'last_restart_type')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->string('last_restart_type', 10)->nullable()->after('last_restart_at');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $columns = ['restart_count', 'last_restart_at', 'last_restart_type'];

        foreach ($this->tables as $table) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, function (Blueprint $blueprint) use ($column) {
                        $blueprint->dropColumn($column);
                    });
                }
            }
        }
    }
};
