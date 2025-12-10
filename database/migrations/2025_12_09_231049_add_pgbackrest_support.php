<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->string('engine')->default('native')->index()->after('enabled');
            $table->string('pgbackrest_backup_type')->nullable()->after('engine');
            $table->string('pgbackrest_compress_type')->nullable()->after('pgbackrest_backup_type');
            $table->unsignedTinyInteger('pgbackrest_compress_level')->nullable()->after('pgbackrest_compress_type');
            $table->string('pgbackrest_log_level')->nullable()->after('pgbackrest_compress_level');
            $table->string('pgbackrest_archive_mode')->nullable()->after('pgbackrest_log_level');
        });

        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->string('engine')->nullable()->index()->after('status');
            $table->string('pgbackrest_backup_type')->nullable()->after('engine');
            $table->string('pgbackrest_label')->nullable()->after('pgbackrest_backup_type');
            $table->string('pgbackrest_stanza')->nullable()->after('pgbackrest_label');
            $table->unsignedBigInteger('pgbackrest_repo_size')->nullable()->after('pgbackrest_stanza');
        });

        Schema::create('pgbackrest_repos', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();

            $table->foreignId('scheduled_database_backup_id')
                ->constrained('scheduled_database_backups')
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('repo_number')->default(1);

            $table->string('type')->default('posix');

            $table->string('path')->nullable();
            $table->foreignId('s3_storage_id')
                ->nullable()
                ->constrained('s3_storages')
                ->nullOnDelete();

            $table->string('retention_full_type')->default('count');
            $table->unsignedInteger('retention_full')->default(2);
            $table->unsignedInteger('retention_diff')->default(7);
            $table->boolean('enabled')->default(true);

            $table->timestamps();

            $table->unique(['scheduled_database_backup_id', 'repo_number']);
        });

        Schema::create('database_restores', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();

            $table->morphs('database');

            $table->foreignId('scheduled_database_backup_execution_id')
                ->nullable()
                ->constrained('scheduled_database_backup_executions')
                ->nullOnDelete();

            $table->string('engine')->default('pgbackrest');
            $table->string('target_label')->nullable();
            $table->timestamp('target_time')->nullable();

            $table->string('status')->default('pending');
            $table->index('status');
            $table->longText('message')->nullable();
            $table->longText('log')->nullable();

            $table->timestamps();
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('database_restores', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
        Schema::dropIfExists('database_restores');
        Schema::dropIfExists('pgbackrest_repos');

        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->dropColumn([
                'engine',
                'pgbackrest_backup_type',
                'pgbackrest_label',
                'pgbackrest_stanza',
                'pgbackrest_repo_size',
            ]);
        });

        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn([
                'engine',
                'pgbackrest_backup_type',
                'pgbackrest_compress_type',
                'pgbackrest_compress_level',
                'pgbackrest_log_level',
                'pgbackrest_archive_mode',
            ]);
        });
    }
};
