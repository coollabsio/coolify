<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->string('resourceable_type')->nullable();
            $table->unsignedBigInteger('resourceable_id')->nullable();
            $table->index(['resourceable_type', 'resourceable_id']);
        });

        // Populate the new columns
        DB::table('environment_variables')->whereNotNull('application_id')
            ->update([
                'resourceable_type' => 'App\\Models\\Application',
                'resourceable_id' => DB::raw('application_id'),
            ]);

        DB::table('environment_variables')->whereNotNull('service_id')
            ->update([
                'resourceable_type' => 'App\\Models\\Service',
                'resourceable_id' => DB::raw('service_id'),
            ]);

        DB::table('environment_variables')->whereNotNull('standalone_postgresql_id')
            ->update([
                'resourceable_type' => 'App\\Models\\StandalonePostgresql',
                'resourceable_id' => DB::raw('standalone_postgresql_id'),
            ]);

        DB::table('environment_variables')->whereNotNull('standalone_redis_id')
            ->update([
                'resourceable_type' => 'App\\Models\\StandaloneRedis',
                'resourceable_id' => DB::raw('standalone_redis_id'),
            ]);

        DB::table('environment_variables')->whereNotNull('standalone_mongodb_id')
            ->update([
                'resourceable_type' => 'App\\Models\\StandaloneMongodb',
                'resourceable_id' => DB::raw('standalone_mongodb_id'),
            ]);

        DB::table('environment_variables')->whereNotNull('standalone_mysql_id')
            ->update([
                'resourceable_type' => 'App\\Models\\StandaloneMysql',
                'resourceable_id' => DB::raw('standalone_mysql_id'),
            ]);

        DB::table('environment_variables')->whereNotNull('standalone_mariadb_id')
            ->update([
                'resourceable_type' => 'App\\Models\\StandaloneMariadb',
                'resourceable_id' => DB::raw('standalone_mariadb_id'),
            ]);

        DB::table('environment_variables')->whereNotNull('standalone_keydb_id')
            ->update([
                'resourceable_type' => 'App\\Models\\StandaloneKeydb',
                'resourceable_id' => DB::raw('standalone_keydb_id'),
            ]);

        DB::table('environment_variables')->whereNotNull('standalone_dragonfly_id')
            ->update([
                'resourceable_type' => 'App\\Models\\StandaloneDragonfly',
                'resourceable_id' => DB::raw('standalone_dragonfly_id'),
            ]);

        DB::table('environment_variables')->whereNotNull('standalone_clickhouse_id')
            ->update([
                'resourceable_type' => 'App\\Models\\StandaloneClickhouse',
                'resourceable_id' => DB::raw('standalone_clickhouse_id'),
            ]);

        // After successful migration, we can drop the old foreign key columns
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn([
                'application_id',
                'service_id',
                'standalone_postgresql_id',
                'standalone_redis_id',
                'standalone_mongodb_id',
                'standalone_mysql_id',
                'standalone_mariadb_id',
                'standalone_keydb_id',
                'standalone_dragonfly_id',
                'standalone_clickhouse_id',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            // Restore the old columns
            $table->unsignedBigInteger('application_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedBigInteger('standalone_postgresql_id')->nullable();
            $table->unsignedBigInteger('standalone_redis_id')->nullable();
            $table->unsignedBigInteger('standalone_mongodb_id')->nullable();
            $table->unsignedBigInteger('standalone_mysql_id')->nullable();
            $table->unsignedBigInteger('standalone_mariadb_id')->nullable();
            $table->unsignedBigInteger('standalone_keydb_id')->nullable();
            $table->unsignedBigInteger('standalone_dragonfly_id')->nullable();
            $table->unsignedBigInteger('standalone_clickhouse_id')->nullable();
        });

        Schema::table('environment_variables', function (Blueprint $table) {
            // Restore data from polymorphic relationship
            DB::table('environment_variables')
                ->where('resourceable_type', 'App\\Models\\Application')
                ->update(['application_id' => DB::raw('resourceable_id')]);

            DB::table('environment_variables')
                ->where('resourceable_type', 'App\\Models\\Service')
                ->update(['service_id' => DB::raw('resourceable_id')]);

            DB::table('environment_variables')
                ->where('resourceable_type', 'App\\Models\\StandalonePostgresql')
                ->update(['standalone_postgresql_id' => DB::raw('resourceable_id')]);

            DB::table('environment_variables')
                ->where('resourceable_type', 'App\\Models\\StandaloneRedis')
                ->update(['standalone_redis_id' => DB::raw('resourceable_id')]);

            DB::table('environment_variables')
                ->where('resourceable_type', 'App\\Models\\StandaloneMongodb')
                ->update(['standalone_mongodb_id' => DB::raw('resourceable_id')]);

            DB::table('environment_variables')
                ->where('resourceable_type', 'App\\Models\\StandaloneMysql')
                ->update(['standalone_mysql_id' => DB::raw('resourceable_id')]);

            DB::table('environment_variables')
                ->where('resourceable_type', 'App\\Models\\StandaloneMariadb')
                ->update(['standalone_mariadb_id' => DB::raw('resourceable_id')]);

            DB::table('environment_variables')
                ->where('resourceable_type', 'App\\Models\\StandaloneKeydb')
                ->update(['standalone_keydb_id' => DB::raw('resourceable_id')]);

            DB::table('environment_variables')
                ->where('resourceable_type', 'App\\Models\\StandaloneDragonfly')
                ->update(['standalone_dragonfly_id' => DB::raw('resourceable_id')]);

            DB::table('environment_variables')
                ->where('resourceable_type', 'App\\Models\\StandaloneClickhouse')
                ->update(['standalone_clickhouse_id' => DB::raw('resourceable_id')]);

            // Drop the polymorphic columns
            $table->dropIndex(['resourceable_type', 'resourceable_id']);
            $table->dropColumn(['resourceable_type', 'resourceable_id']);
        });
    }
};
