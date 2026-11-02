<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_volumes', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_workload_id')->constrained()->cascadeOnDelete();
            // Where the data lives. Null until the first deployment creates the volume.
            $table->foreignId('node_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('mount_path', 4096);
            // Removed from the configuration; the data stays until the user deletes it.
            $table->timestamp('detached_at')->nullable();
            $table->timestamps();
            $table->index(['node_workload_id', 'detached_at']);
        });

        Schema::create('node_volume_copies', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('node_volume_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->string('state', 16);
            $table->timestamp('delete_after')->nullable();
            $table->timestamps();
            $table->unique(['node_volume_id', 'node_id']);
            $table->index(['state', 'delete_after']);
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->json('volume_inventory')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('volume_inventory');
        });
        Schema::dropIfExists('node_volume_copies');
        Schema::dropIfExists('node_volumes');
    }
};
