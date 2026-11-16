<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The desired set of the workloads of a Node that Sentinel converges (decision 0026): one entry
     * for each workload on a Node with `workload.reconcile.v1`, and the generation and the hash of
     * the set that Sentinel accepted last. Sentinel events of changes that it made alone go to
     * `node_workload_events` for the deployment history.
     */
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->unsignedBigInteger('workload_set_generation')->default(0);
            $table->string('workload_set_hash', 64)->nullable();
        });

        Schema::create('node_workload_set_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_workload_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_workload_revision_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_operation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('desired_state');
            $table->string('container_slot');
            $table->string('replaces_slot')->nullable();
            $table->unsignedBigInteger('network_revision')->default(0);
            $table->string('pull_policy')->default('missing');
            $table->boolean('redeploy')->default(false);
            $table->string('entry_hash', 64)->nullable();
            $table->string('status')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestamp('status_at')->nullable();
            $table->timestamps();

            $table->unique(['node_id', 'node_workload_id']);
        });

        Schema::create('node_workload_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_workload_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_workload_revision_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_id')->unique();
            $table->string('status');
            $table->text('reason')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index(['node_workload_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_workload_events');
        Schema::dropIfExists('node_workload_set_entries');
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['workload_set_generation', 'workload_set_hash']);
        });
    }
};
