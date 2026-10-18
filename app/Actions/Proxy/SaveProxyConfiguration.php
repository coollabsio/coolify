<?php

namespace App\Actions\Proxy;

use App\Models\Server;
use App\Services\ProxyPortParser;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

class SaveProxyConfiguration
{
    use AsAction;

    private const MAX_BACKUPS = 10;

    public function handle(Server $server, string $configuration): void
    {
        try {
            ProxyPortParser::fromConfiguration($configuration);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'configuration' => [$exception->getMessage()],
            ]);
        }

        $proxy_path = $server->proxyPath();
        $docker_compose_yml_base64 = base64_encode($configuration);
        $new_hash = str($docker_compose_yml_base64)->pipe('md5')->value;

        // Only create a backup if the configuration actually changed
        $old_hash = $server->proxy->get('last_saved_settings');
        $config_changed = $old_hash && $old_hash !== $new_hash;

        // Update the saved settings hash and store full config as database backup
        $server->proxy->last_saved_settings = $new_hash;
        $server->proxy->last_saved_proxy_configuration = $configuration;
        $server->save();

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
}
