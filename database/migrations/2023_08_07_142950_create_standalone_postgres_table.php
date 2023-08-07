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
        Schema::create('standalone_postgres', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->string('description')->nullable();

            $table->string('postgres_user')->default('postgres');
            $table->string('postgres_password');
            $table->string('postgres_db')->default('postgres');
            $table->string('postgres_initdb_args')->nullable();
            $table->string('postgres_host_auth_method')->nullable();
            $table->json('init_scripts')->nullable();

            $table->boolean('is_public')->default(false);
            $table->integer('public_port')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->morphs('destination');

            $table->foreignId('environment_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('standalone_postgres');
    }
};