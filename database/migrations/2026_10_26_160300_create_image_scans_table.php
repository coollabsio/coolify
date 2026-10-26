<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_scans', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('image_sbom_id')->nullable()->constrained('image_sboms')->nullOnDelete();
            $table->string('image_id', 80);
            $table->string('repo_digest', 80)->nullable();
            $table->morphs('resource');
            $table->string('image_ref', 512);
            $table->foreignId('deployment_queue_id')->nullable()->constrained('application_deployment_queues')->nullOnDelete();
            $table->string('status');
            $table->string('error_code')->nullable();
            $table->jsonb('error_context')->nullable();
            $table->jsonb('summary')->nullable();
            $table->jsonb('findings')->nullable();
            $table->jsonb('candidates')->nullable();
            $table->string('base_image_ref', 512)->nullable();
            $table->boolean('builds_image')->default(false);
            $table->boolean('is_baseline')->default(true);
            $table->timestamp('grype_db_built_at')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->unique(['resource_type', 'resource_id', 'image_id']);
            $table->index(['server_id', 'image_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_scans');
    }
};
