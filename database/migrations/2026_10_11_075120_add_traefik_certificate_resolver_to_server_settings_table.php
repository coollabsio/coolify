<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Yaml\Yaml;

return new class extends Migration
{
    /**
     * Add the Traefik certificate resolver setting. Servers whose saved Traefik configuration has no
     * letsencrypt resolver get no resolver, because Traefik ignored that label and only logged an error.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('server_settings', 'traefik_certificate_resolver')) {
            Schema::table('server_settings', function (Blueprint $table) {
                $table->string('traefik_certificate_resolver')->nullable()->default('letsencrypt');
            });
        }

        DB::table('servers')
            ->select(['id', 'proxy'])
            ->orderBy('id')
            ->chunkById(100, function ($servers): void {
                foreach ($servers as $server) {
                    try {
                        if ($this->hasNoLetsEncryptResolver($server->proxy)) {
                            DB::table('server_settings')
                                ->where('server_id', $server->id)
                                ->where('traefik_certificate_resolver', 'letsencrypt')
                                ->update(['traefik_certificate_resolver' => null]);
                        }
                    } catch (Throwable $e) {
                        Log::warning("Could not check the Traefik certificate resolver of server {$server->id}: {$e->getMessage()}");
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn('traefik_certificate_resolver');
        });
    }

    /**
     * True only when the saved configuration is a Traefik compose file without an active
     * --certificatesresolvers.letsencrypt.* option. Unknown or missing configurations return false.
     */
    private function hasNoLetsEncryptResolver(?string $proxy): bool
    {
        $proxy = json_decode($proxy ?? '', true);
        if (strtoupper((string) data_get($proxy, 'type')) !== 'TRAEFIK') {
            return false;
        }

        $configuration = data_get($proxy, 'last_saved_proxy_configuration');
        if (blank($configuration)) {
            return false;
        }

        $traefik = collect(data_get(Yaml::parse($configuration), 'services', []))
            ->first(fn ($service) => is_array($service) && str_contains((string) data_get($service, 'image'), 'traefik'));
        if (! $traefik) {
            return false;
        }

        $command = data_get($traefik, 'command', []);
        $options = is_array($command) ? $command : preg_split('/\s+/', (string) $command);

        return collect($options)->doesntContain(
            fn ($option) => is_string($option) && str_starts_with(strtolower(trim($option, " \t'\"")), '--certificatesresolvers.letsencrypt.')
        );
    }
};
