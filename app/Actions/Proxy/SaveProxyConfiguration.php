<?php

namespace App\Actions\Proxy;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class SaveProxyConfiguration
{
    use AsAction;

    private const MAX_BACKUPS = 10;

    public function handle(Server $server, string $configuration): void
    {
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
            // Skip backup if a file with the same hash already exists (identical content)
            $commands[] = "ls $backup_path/docker-compose.*.$short_hash.yml 1>/dev/null 2>&1 || cp -f $proxy_path/docker-compose.yml $backup_path/$backup_file 2>/dev/null || true";
            // Prune old backups, keep only the most recent ones
            $commands[] = 'cd '.$backup_path.' && ls -1t docker-compose.*.yml 2>/dev/null | tail -n +'.((int) self::MAX_BACKUPS + 1).' | xargs rm -f 2>/dev/null || true';
        }

        $commands[] = "echo '$docker_compose_yml_base64' | base64 -d | tee $proxy_path/docker-compose.yml > /dev/null";

        instant_remote_process($commands, $server);
    }
}
