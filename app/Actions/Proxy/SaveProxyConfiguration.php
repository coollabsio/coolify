<?php

namespace App\Actions\Proxy;

use App\Models\ProxyConfigurationVersion;
use App\Models\Server;
use App\Services\ProxyPortParser;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

class SaveProxyConfiguration
{
    use AsAction;

    private const MAX_BACKUPS = 10;

    /**
     * @param  string  $source  Where the change comes from: ui, api, reset, restore, or system.
     */
    public function handle(Server $server, string $configuration, string $source = 'system'): void
    {
        try {
            ProxyPortParser::fromConfiguration($configuration);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'configuration' => [$exception->getMessage()],
            ]);
        }

        if (! $server->isSwarm()) {
            $this->ensureDefaultContainerName($configuration);
        }

        $proxy_path = $server->proxyPath();
        $docker_compose_yml_base64 = base64_encode($configuration);
        $new_hash = str($docker_compose_yml_base64)->pipe('md5')->value;

        // Only create a backup if the configuration actually changed
        $old_hash = $server->proxy->get('last_saved_settings');
        $config_changed = $old_hash && $old_hash !== $new_hash;

        $previous_configuration = $server->proxy->get('last_saved_proxy_configuration');

        // Update the saved settings hash and store full config as database backup
        $server->proxy->last_saved_settings = $new_hash;
        $server->proxy->last_saved_proxy_configuration = $configuration;
        $server->save();

        $this->recordVersion($server, $configuration, $new_hash, $source, $previous_configuration);

        $backup_path = "$proxy_path/backups";

        // Transfer the configuration file to the server, with backup if changed
        $commands = ["mkdir -p $proxy_path"];

        if ($config_changed) {
            $short_hash = substr($old_hash, 0, 8);
            $timestamp = now()->format('Y-m-d_H-i-s');
            $backup_file = "docker-compose.{$timestamp}.{$short_hash}.yml";
            $commands[] = "mkdir -p $backup_path";
            // Skip backup if a file with the same hash already exists (identical content).
            // find instead of a shell glob, and no `|` with `||`: the non-root sudo parser would need bash -c,
            // and the SSH user may not be able to read the directory (#4255).
            $commands[] = "if [ -z \"$(find $backup_path -maxdepth 1 -name 'docker-compose.*.$short_hash.yml')\" ]; then";
            $commands[] = "    cp -f $proxy_path/docker-compose.yml $backup_path/$backup_file 2>/dev/null || true";
            $commands[] = 'fi';
            // Prune old backups, keep only the most recent ones (the timestamp in the name sorts by age)
            $commands[] = "find $backup_path -maxdepth 1 -name 'docker-compose.*.yml' | sort -r | tail -n +".((int) self::MAX_BACKUPS + 1).' | xargs -r rm -f';
        }

        $commands[] = "echo '$docker_compose_yml_base64' | base64 -d | tee $proxy_path/docker-compose.yml > /dev/null";

        instant_remote_process($commands, $server);
    }

    /**
     * Saves a version when the configuration differs from the newest version. The first version of a server
     * starts from the configuration saved before the history existed, so an upgrade keeps a version to go back to.
     */
    private function recordVersion(Server $server, string $configuration, string $hash, string $source, mixed $previousConfiguration): void
    {
        $newest = $server->proxyConfigurationVersions()->first();
        if ($newest?->hash === $hash) {
            return;
        }

        if (! $newest && is_string($previousConfiguration) && filled($previousConfiguration)) {
            $previousHash = ProxyConfigurationVersion::hashOf($previousConfiguration);
            if ($previousHash !== $hash) {
                $server->proxyConfigurationVersions()->create([
                    'configuration' => $previousConfiguration,
                    'hash' => $previousHash,
                    'source' => 'system',
                    'applied_at' => $previousHash === $server->proxy->get('last_applied_settings') ? now() : null,
                ]);
            }
        }

        $server->proxyConfigurationVersions()->create([
            'configuration' => $configuration,
            'hash' => $hash,
            'source' => $source,
            'user_id' => auth()->id(),
        ]);

        $keep = $server->proxyConfigurationVersions()->limit(ProxyConfigurationVersion::MAX_VERSIONS)->pluck('id');
        $applied = $server->proxyConfigurationVersions()->reorder()->whereNotNull('applied_at')->orderByDesc('applied_at')->orderByDesc('id')->value('id');

        $server->proxyConfigurationVersions()
            ->reorder()
            ->whereNotIn('id', $applied ? $keep->push($applied) : $keep)
            ->delete();
    }

    /**
     * Coolify finds the proxy by its container name, so a renamed or unnamed proxy shows as stopped (#7083).
     */
    private function ensureDefaultContainerName(string $configuration): void
    {
        $services = data_get(Yaml::parse($configuration, Yaml::PARSE_CUSTOM_TAGS), 'services');

        foreach (['traefik', 'caddy'] as $proxyService) {
            if (! is_array($services) || ! array_key_exists($proxyService, $services)) {
                continue;
            }

            $containerName = data_get($services, "{$proxyService}.container_name");
            if ($containerName instanceof TaggedValue) {
                $containerName = $containerName->getValue();
            }

            if ($containerName !== 'coolify-proxy') {
                throw ValidationException::withMessages([
                    'configuration' => ["The {$proxyService} container_name must be coolify-proxy. Coolify uses this name to manage the proxy."],
                ]);
            }
        }
    }
}
