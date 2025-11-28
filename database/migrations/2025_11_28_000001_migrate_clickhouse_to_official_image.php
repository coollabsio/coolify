<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\StandaloneClickhouse;
use App\Models\LocalPersistentVolume;

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
        if (!Schema::hasColumn('standalone_clickhouses', 'clickhouse_db')) {
            Schema::table('standalone_clickhouses', function (Blueprint $table) {
                $table->string('clickhouse_db')
                    ->default('default')
                    ->after('clickhouse_admin_password');
            });
        }
        StandaloneClickhouse::where(function ($query) {
                $query->where('image', 'like', '%bitnami/clickhouse%')
                      ->orWhere('image', 'like', '%bitnamilegacy/clickhouse%');
            })
            ->update([
                'image' => 'clickhouse/clickhouse-server:latest',
                'clickhouse_db' => DB::raw("COALESCE(clickhouse_db, 'default')")
            ]);

        LocalPersistentVolume::where('resource_type', StandaloneClickhouse::class)
            ->where('mount_path', '/bitnami/clickhouse')
            ->update(['mount_path' => '/var/lib/clickhouse']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        StandaloneClickhouse::where('image', 'clickhouse/clickhouse-server:latest')
            ->update(['image' => 'bitnami/clickhouse']);
        LocalPersistentVolume::where('resource_type', StandaloneClickhouse::class)
            ->where('mount_path', '/var/lib/clickhouse')
            ->update(['mount_path' => '/bitnami/clickhouse']);
    }
};
