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
        Schema::create('postgresql_wal_backup_executions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('postgresql_wal_backup_configuration_id')
                ->constrained(indexName: 'pgsql_wal_executions_configuration_foreign')
                ->cascadeOnDelete();
            $table->enum('operation', ['configure', 'base_backup', 'retention', 'health_check', 'restore']);
            $table->enum('status', ['running', 'success', 'failed'])->default('running');
            $table->longText('message')->nullable();
            $table->string('backup_name')->nullable();
            $table->timestampTz('target_time')->nullable();
            $table->foreignId('restored_database_id')
                ->nullable()
                ->constrained('standalone_postgresqls', indexName: 'pgsql_wal_executions_restored_database_foreign')
                ->nullOnDelete();
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->index(
                ['postgresql_wal_backup_configuration_id', 'created_at'],
                'pgsql_wal_executions_configuration_created_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('postgresql_wal_backup_executions');
    }
};
