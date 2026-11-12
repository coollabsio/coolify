<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Node volume backups (decision 0009): the checksum of each archive, and copies with their own
     * Podman name. A restore on the Node of the volume keeps the old data in a second volume there.
     */
    public function up(): void
    {
        Schema::table('scheduled_volume_backup_executions', function (Blueprint $table) {
            $table->string('sha256', 64)->nullable();
        });

        Schema::table('node_volume_copies', function (Blueprint $table) {
            // Null: the name of the volume, `coolify-vol-<volume uuid>`.
            $table->string('podman_name')->nullable();
            $table->dropUnique(['node_volume_id', 'node_id']);
            $table->unique(['node_volume_id', 'node_id', 'podman_name']);
        });
    }

    public function down(): void
    {
        Schema::table('node_volume_copies', function (Blueprint $table) {
            $table->dropUnique(['node_volume_id', 'node_id', 'podman_name']);
            $table->unique(['node_volume_id', 'node_id']);
            $table->dropColumn('podman_name');
        });

        Schema::table('scheduled_volume_backup_executions', function (Blueprint $table) {
            $table->dropColumn('sha256');
        });
    }
};
