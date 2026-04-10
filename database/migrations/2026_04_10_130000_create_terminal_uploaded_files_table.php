<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_uploaded_files', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('upload_token');
            $table->string('container_uuid')->nullable();
            $table->string('original_name');
            $table->string('stored_filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->text('local_path');
            $table->text('server_path')->nullable();
            $table->text('container_path')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedInteger('cleanup_attempts')->default(0);
            $table->text('last_cleanup_error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'team_id', 'upload_token']);
            $table->index(['status', 'expires_at']);
            $table->index(['user_id', 'team_id', 'status']);
            $table->index(['server_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_uploaded_files');
    }
};
