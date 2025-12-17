<?php

use App\Models\LocalPersistentVolume;
use App\Models\StandaloneClickhouse;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Migrates existing ClickHouse instances from Bitnami/BinamiLegacy images
     * to the official clickhouse/clickhouse-server image.
     */
    public function up(): void
    {
        // Add clickhouse_db column if it doesn't exist
        if (! Schema::hasColumn('standalone_clickhouses', 'clickhouse_db')) {
            Schema::table('standalone_clickhouses', function (Blueprint $table) {
                $table->string('clickhouse_db')
                    ->default('default')
                    ->after('clickhouse_admin_password');
            });
        }

        // Change the default value for the 'image' column to the official image
        Schema::table('standalone_clickhouses', function (Blueprint $table) {
            $table->string('image')->default('clickhouse/clickhouse-server:25.11')->change();
        });

        // Update existing ClickHouse instances from Bitnami images to official image
        StandaloneClickhouse::where(function ($query) {
            $query->where('image', 'like', '%bitnami/clickhouse%')
                ->orWhere('image', 'like', '%bitnamilegacy/clickhouse%');
        })
            ->update([
                'image' => 'clickhouse/clickhouse-server:25.11',
                'clickhouse_db' => DB::raw("COALESCE(clickhouse_db, 'default')"),
            ]);

        // Update volume mount paths from Bitnami to official image paths
        LocalPersistentVolume::where('resource_type', StandaloneClickhouse::class)
            ->where('mount_path', '/bitnami/clickhouse')
            ->update(['mount_path' => '/var/lib/clickhouse']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert the default value for the 'image' column
        Schema::table('standalone_clickhouses', function (Blueprint $table) {
            $table->string('image')->default('bitnamilegacy/clickhouse')->change();
        });

        // Revert existing ClickHouse instances back to Bitnami image
        StandaloneClickhouse::where('image', 'clickhouse/clickhouse-server:25.11')
            ->update(['image' => 'bitnamilegacy/clickhouse']);

        // Revert volume mount paths
        LocalPersistentVolume::where('resource_type', StandaloneClickhouse::class)
            ->where('mount_path', '/var/lib/clickhouse')
            ->update(['mount_path' => '/bitnami/clickhouse']);
    }
};
