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
        'standalone_sqlites',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'redact_all_env_values_in_logs')) {
                continue;
            }
            Schema::table($table, function (Blueprint $table) {
                $table->boolean('redact_all_env_values_in_logs')->default(true);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'redact_all_env_values_in_logs')) {
                continue;
            }
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('redact_all_env_values_in_logs');
            });
        }
    }
};
