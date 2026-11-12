<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Supports PrivateKey::servers() lookups (isInUse(), refresh_server_connection()), which always exclude
     * soft-deleted servers. No query reads servers by private key with trashed rows, so only active servers are indexed.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_servers_private_key_id_active ON servers (private_key_id) WHERE deleted_at IS NULL');

            return;
        }

        if (! Schema::hasIndex('servers', 'idx_servers_private_key_id_active')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->index('private_key_id', 'idx_servers_private_key_id_active');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_servers_private_key_id_active');

            return;
        }

        if (Schema::hasIndex('servers', 'idx_servers_private_key_id_active')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->dropIndex('idx_servers_private_key_id_active');
            });
        }
    }
};
