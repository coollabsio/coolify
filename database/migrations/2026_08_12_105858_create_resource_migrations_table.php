<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_migrations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('direction');
            $table->string('status')->default('pending');
            $table->string('storage_driver')->default('s3');
            $table->text('storage_config')->nullable();
            $table->longText('manifest')->nullable();
            $table->boolean('skip_data')->default(false);
            $table->string('destination_uuid')->nullable();
            $table->string('project_uuid')->nullable();
            $table->string('environment_uuid')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('resource_migration_items', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('resource_migration_id')->constrained('resource_migrations')->cascadeOnDelete();
            $table->string('resource_type');
            $table->string('source_uuid');
            $table->string('target_uuid')->nullable();
            $table->string('name');
            $table->string('status')->default('pending');
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('archives')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['resource_migration_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_migration_items');
        Schema::dropIfExists('resource_migrations');
    }
};
