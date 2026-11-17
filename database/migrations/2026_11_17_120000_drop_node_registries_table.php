<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the built-in registry (zot) of the clusters (decision 0030). Builds stay on the servers by
 * default, and each server copies a local image from a server that has it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('node_clusters')->where('image_storage', 'built_in')->update(['image_storage' => null]);
        // Recovery no longer knows the registry command, so it would not close an open one.
        DB::table('node_operations')
            ->where('command_type', 'registry.reconcile.v1')
            ->whereIn('status', ['queued', 'dispatched', 'running', 'verifying', 'uncertain'])
            ->update(['status' => 'failed', 'error' => 'The built-in registry was removed.', 'completed_at' => now()]);
        Schema::dropIfExists('node_registries');
    }

    public function down(): void
    {
        Schema::create('node_registries', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('node_cluster_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('selected_node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->foreignId('node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->text('ca_certificate_pem');
            $table->text('ca_private_key_pem');
            $table->text('certificate_pem')->nullable();
            $table->text('private_key_pem')->nullable();
            $table->timestamp('certificate_valid_until')->nullable();
            $table->text('push_password');
            $table->text('pull_password');
            $table->text('htpasswd');
            $table->unsignedSmallInteger('keep_tags')->default(10);
            $table->timestamps();
        });
    }
};
