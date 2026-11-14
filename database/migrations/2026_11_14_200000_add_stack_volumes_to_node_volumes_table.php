<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A volume that the services of one stack share (decision 0024) belongs to the stack, not to one
 * workload. Each service that mounts it has a row in `node_volume_mounts` with its path and mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_volumes', function (Blueprint $table) {
            $table->foreignId('node_workload_id')->nullable()->change();
            $table->string('mount_path', 4096)->nullable()->change();
            $table->foreignId('node_stack_id')->nullable()->after('node_workload_id')->constrained()->cascadeOnDelete();
        });

        Schema::create('node_volume_mounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_volume_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_workload_id')->constrained()->cascadeOnDelete();
            $table->string('mount_path', 4096);
            $table->boolean('read_only')->default(false);
            $table->timestamps();
            $table->unique(['node_volume_id', 'node_workload_id']);
            $table->index('node_workload_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_volume_mounts');
        Schema::table('node_volumes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('node_stack_id');
        });
    }
};
