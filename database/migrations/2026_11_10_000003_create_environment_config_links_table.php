<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environment_config_links', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('environment_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mode', 16);
            $table->nullableMorphs('source');
            $table->foreignId('private_key_id')->nullable()->constrained()->nullOnDelete();
            $table->string('repository')->nullable();
            $table->string('branch')->nullable();
            $table->string('file_path')->default('coolify.json');
            $table->foreignId('read_server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->text('webhook_secret')->nullable();
            $table->boolean('auto_apply')->default(true);
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pending_commit_sha')->nullable();
            $table->unsignedBigInteger('last_applied_revision_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_config_links');
    }
};
