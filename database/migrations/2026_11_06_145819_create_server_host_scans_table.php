<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_host_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('error_code')->nullable();
            $table->jsonb('error_context')->nullable();
            $table->jsonb('eol')->nullable();
            $table->jsonb('summary')->nullable();
            $table->jsonb('findings')->nullable();
            $table->boolean('findings_truncated')->default(false);
            $table->timestamp('grype_db_built_at')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_host_scans');
    }
};
