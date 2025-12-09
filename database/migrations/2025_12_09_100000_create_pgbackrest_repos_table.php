<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pgbackrest_repos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('standalone_postgresql_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('type'); // 'posix' or 's3'

            $table->unsignedTinyInteger('repo_index'); // 1-8, unique per database

            $table->string('path'); // Local path or S3 path prefix

            $table->foreignId('s3_storage_id')
                ->nullable()
                ->constrained('s3_storages')
                ->nullOnDelete();

            $table->unsignedInteger('retention_full')->nullable();
            $table->unsignedInteger('retention_diff')->nullable();
            $table->string('retention_full_type')->nullable(); // 'count' or 'time'
            $table->unsignedInteger('retention_archive')->nullable();
            $table->string('retention_archive_type')->nullable(); // 'full', 'diff', 'incr'

            $table->timestamps();

            $table->unique(['standalone_postgresql_id', 'repo_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pgbackrest_repos');
    }
};
