<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pgbackrest_repo_scheduled_backup', function (Blueprint $table) {
            $table->id();

            $table->foreignId('scheduled_database_backup_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('pgbackrest_repo_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['scheduled_database_backup_id', 'pgbackrest_repo_id'], 'schedule_repo_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pgbackrest_repo_scheduled_backup');
    }
};
