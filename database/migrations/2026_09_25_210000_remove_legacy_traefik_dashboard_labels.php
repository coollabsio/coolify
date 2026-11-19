<?php

use App\Models\Server;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Save each server on its own. In Postgres one failed update aborts a shared transaction and stops the upgrade.
     */
    public $withinTransaction = false;

    /**
     * Remove the Traefik dashboard router labels from saved proxy configurations.
     * Only the database is changed. The proxy shows a restart notice and applies the fix on the next restart.
     */
    public function up(): void
    {
        Server::query()->chunkById(100, function ($servers) {
            foreach ($servers as $server) {
                try {
                    removeLegacyTraefikDashboardExposure($server);
                } catch (Throwable $e) {
                    Log::warning('Could not remove legacy Traefik dashboard labels', [
                        'server_id' => $server->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        //
    }
};
