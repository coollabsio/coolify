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
        Schema::create('standalone_dragonflies', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->string('description')->nullable();

            $table->text('dragonfly_password');

            $table->boolean('is_log_drain_enabled')->default(false);
            $table->boolean('is_include_timestamps')->default(false);
            $table->softDeletes();

            $table->string('status')->default('exited');

            $table->string('image')->default('docker.dragonflydb.io/dragonflydb/dragonfly');

            $table->boolean('is_public')->default(false);
            $table->integer('public_port')->nullable();
            $table->text('ports_mappings')->nullable();

            $table->string('limits_memory')->default('0');
            $table->string('limits_memory_swap')->default('0');
            $table->integer('limits_memory_swappiness')->default(60);
            $table->string('limits_memory_reservation')->default('0');

            $table->string('limits_cpus')->default('0');
            $table->string('limits_cpuset')->nullable()->default(null);
            $table->integer('limits_cpu_shares')->default(1024);

            $table->timestamp('started_at')->nullable();
            $table->morphs('destination');
            $table->foreignId('environment_id')->nullable();
            $table->timestamps();
        });
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->foreignId('standalone_dragonfly_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('standalone_dragonflies');
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn('standalone_dragonfly_id');
        });
    }
};
