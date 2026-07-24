<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('postgresql_wal_backup_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('standalone_postgresql_id')
                ->constrained(indexName: 'pgsql_wal_configs_database_foreign')
                ->cascadeOnDelete();
            $table->foreignId('s3_storage_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('enabled')->default(true)->index();
            $table->string('base_backup_frequency')->default('0 3 * * *');
            $table->unsignedInteger('archive_timeout_seconds')->default(60);
            $table->enum('wal_level', ['replica', 'logical'])->default('replica');
            $table->unsignedInteger('retention_full_backups')->default(7);
            $table->unsignedInteger('timeout')->default(3600);
            $table->unsignedSmallInteger('postgres_major_version');
            $table->enum('status', ['disabled', 'pending_restart', 'healthy', 'warning', 'failed'])
                ->default('warning')
                ->index();
            $table->text('last_health_message')->nullable();
            $table->string('last_archived_wal')->nullable();
            $table->timestampTz('last_archived_at')->nullable();
            $table->string('last_failed_wal')->nullable();
            $table->timestampTz('last_failed_at')->nullable();
            $table->unsignedInteger('last_failed_count')->default(0);
            $table->timestampTz('last_base_backup_at')->nullable();
            $table->timestampTz('last_successful_base_backup_at')->nullable();
            $table->timestamps();

            $table->unique('standalone_postgresql_id', 'pgsql_wal_configs_database_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('postgresql_wal_backup_configurations');
    }
};
