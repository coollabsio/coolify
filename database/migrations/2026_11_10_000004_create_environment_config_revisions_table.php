<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environment_config_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('link_id')->constrained('environment_config_links')->cascadeOnDelete();
            $table->string('trigger', 16);
            $table->string('commit_sha')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->longText('file_contents')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('state_hash', 64)->nullable();
            $table->unsignedSmallInteger('format_version')->nullable();
            $table->json('plan')->nullable();
            $table->string('status', 24)->index();
            $table->text('error')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('delete_data')->nullable();
            $table->json('held_deployments')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_config_revisions');
    }
};
