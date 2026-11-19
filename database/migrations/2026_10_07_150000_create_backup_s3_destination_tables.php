<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_database_backup_s3_storage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_database_backup_id')
                ->constrained(indexName: 'db_backup_s3_storage_backup_fk')
                ->cascadeOnDelete();
            $table->foreignId('s3_storage_id')
                ->constrained(indexName: 'db_backup_s3_storage_storage_fk')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['scheduled_database_backup_id', 's3_storage_id'], 'db_backup_s3_storage_unique');
        });

        Schema::create('scheduled_volume_backup_s3_storage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_volume_backup_id')
                ->constrained(indexName: 'volume_backup_s3_storage_backup_fk')
                ->cascadeOnDelete();
            $table->foreignId('s3_storage_id')
                ->constrained(indexName: 'volume_backup_s3_storage_storage_fk')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['scheduled_volume_backup_id', 's3_storage_id'], 'volume_backup_s3_storage_unique');
        });

        Schema::create('database_backup_s3_replicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')
                ->index()
                ->constrained('scheduled_database_backup_executions', indexName: 'db_backup_s3_replicas_execution_fk')
                ->cascadeOnDelete();
            $table->foreignId('s3_storage_id')
                ->nullable()
                ->constrained(indexName: 'db_backup_s3_replicas_storage_fk')
                ->nullOnDelete();
            $table->boolean('s3_uploaded')->nullable();
            $table->boolean('s3_storage_deleted')->default(false);
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('volume_backup_s3_replicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')
                ->index()
                ->constrained('scheduled_volume_backup_executions', indexName: 'volume_backup_s3_replicas_execution_fk')
                ->cascadeOnDelete();
            $table->foreignId('s3_storage_id')
                ->nullable()
                ->constrained(indexName: 'volume_backup_s3_replicas_storage_fk')
                ->nullOnDelete();
            $table->boolean('s3_uploaded')->nullable();
            $table->boolean('s3_storage_deleted')->default(false);
            $table->text('message')->nullable();
            $table->timestamps();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('volume_backup_s3_replicas');
        Schema::dropIfExists('database_backup_s3_replicas');
        Schema::dropIfExists('scheduled_volume_backup_s3_storage');
        Schema::dropIfExists('scheduled_database_backup_s3_storage');
    }

    /**
     * Copies the single S3 destination of existing schedules and executions into the new tables.
     */
    private function backfill(): void
    {
        $now = now();

        DB::table('scheduled_database_backup_s3_storage')->insertUsing(
            ['scheduled_database_backup_id', 's3_storage_id', 'created_at', 'updated_at'],
            DB::table('scheduled_database_backups')
                ->join('s3_storages', 's3_storages.id', '=', 'scheduled_database_backups.s3_storage_id')
                ->select('scheduled_database_backups.id', 'scheduled_database_backups.s3_storage_id')
                ->selectRaw('?, ?', [$now, $now]),
        );

        DB::table('scheduled_volume_backup_s3_storage')->insertUsing(
            ['scheduled_volume_backup_id', 's3_storage_id', 'created_at', 'updated_at'],
            DB::table('scheduled_volume_backups')
                ->whereNotNull('s3_storage_id')
                ->select('id', 's3_storage_id')
                ->selectRaw('?, ?', [$now, $now]),
        );

        // Database executions have no storage column: the copy went to the storage of the schedule.
        DB::table('database_backup_s3_replicas')->insertUsing(
            ['execution_id', 's3_storage_id', 's3_uploaded', 's3_storage_deleted', 'created_at', 'updated_at'],
            DB::table('scheduled_database_backup_executions')
                ->join('scheduled_database_backups', 'scheduled_database_backups.id', '=', 'scheduled_database_backup_executions.scheduled_database_backup_id')
                ->leftJoin('s3_storages', 's3_storages.id', '=', 'scheduled_database_backups.s3_storage_id')
                ->whereNotNull('scheduled_database_backup_executions.s3_uploaded')
                ->select(
                    'scheduled_database_backup_executions.id',
                    's3_storages.id',
                    'scheduled_database_backup_executions.s3_uploaded',
                    'scheduled_database_backup_executions.s3_storage_deleted',
                )
                ->selectRaw('?, ?', [$now, $now]),
        );

        DB::table('volume_backup_s3_replicas')->insertUsing(
            ['execution_id', 's3_storage_id', 's3_uploaded', 's3_storage_deleted', 'created_at', 'updated_at'],
            DB::table('scheduled_volume_backup_executions')
                ->whereNotNull('s3_storage_id')
                ->select('id', 's3_storage_id', 's3_uploaded', 's3_storage_deleted')
                ->selectRaw('?, ?', [$now, $now]),
        );
    }
};
