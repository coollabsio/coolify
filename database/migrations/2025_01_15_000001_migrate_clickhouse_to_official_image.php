<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration updates ClickHouse instances from the deprecated Bitnami image
     * to the official clickhouse/clickhouse-server:lts image.
     */
    public function up(): void
    {
        // Add clickhouse_db column for database name
        Schema::table('standalone_clickhouses', function (Blueprint $table) {
            $table->string('clickhouse_db')->default('default')->after('clickhouse_admin_password');
        });

        // Update default image to official ClickHouse image
        Schema::table('standalone_clickhouses', function (Blueprint $table) {
            $table->string('image')->default('clickhouse/clickhouse-server:lts')->change();
        });

        // Migrate existing instances to the official image
        DB::table('standalone_clickhouses')
            ->whereIn('image', ['bitnami/clickhouse', 'bitnamilegacy/clickhouse'])
            ->update([
                'image' => 'clickhouse/clickhouse-server:lts',
                'clickhouse_db' => 'default',
            ]);

        // Update persistent volumes mount path for ClickHouse
        // The official image uses /var/lib/clickhouse instead of /bitnami/clickhouse
        DB::table('local_persistent_volumes')
            ->where('resource_type', 'App\\Models\\StandaloneClickhouse')
            ->where('mount_path', '/bitnami/clickhouse')
            ->update(['mount_path' => '/var/lib/clickhouse']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert persistent volumes mount path
        DB::table('local_persistent_volumes')
            ->where('resource_type', 'App\\Models\\StandaloneClickhouse')
            ->where('mount_path', '/var/lib/clickhouse')
            ->update(['mount_path' => '/bitnami/clickhouse']);

        // Revert image changes
        DB::table('standalone_clickhouses')
            ->where('image', 'clickhouse/clickhouse-server:lts')
            ->update([
                'image' => 'bitnamilegacy/clickhouse',
            ]);

        // Revert default image
        Schema::table('standalone_clickhouses', function (Blueprint $table) {
            $table->string('image')->default('bitnamilegacy/clickhouse')->change();
        });

        // Remove clickhouse_db column
        Schema::table('standalone_clickhouses', function (Blueprint $table) {
            $table->dropColumn('clickhouse_db');
        });
    }
};
