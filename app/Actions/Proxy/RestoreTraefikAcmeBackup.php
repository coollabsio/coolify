<?php

namespace App\Actions\Proxy;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class RestoreTraefikAcmeBackup
{
    use AsAction;

    /**
     * Replace acme.json with a backup. The current acme.json is backed up first.
     */
    public function handle(Server $server, string $backupName): void
    {
        $backupName = ListTraefikAcmeBackups::resolve($server, $backupName);
        $directory = ListTraefikAcmeBackups::directory($server);
        $backup = ListTraefikAcmeBackups::backupCommands($server);
        $restorePath = "{$directory}/.acme.json.restore-".bin2hex(random_bytes(8)).'.tmp';

        $source = escapeshellarg("{$directory}/{$backupName}");
        $temporary = escapeshellarg($restorePath);

        // Copy the chosen backup before pruning, which may remove it when it is the oldest one.
        $script = implode('; ', [
            'set -e',
            'trap '.escapeshellarg('rm -f -- '.escapeshellarg($restorePath).' '.escapeshellarg($backup['temporary_path'])).' EXIT',
            'umask 077',
            "[ -f {$source} ]",
            "cp -- {$source} {$temporary}",
            "chmod 600 {$temporary}",
            $backup['script'],
            "mv -- {$temporary} ".escapeshellarg("{$directory}/acme.json"),
            ListTraefikAcmeBackups::pruneCommands($server),
        ]);

        instant_remote_process(['sh -c '.escapeshellarg($script)], $server);

        // Traefik reads acme.json only when it starts.
        $server->proxy->certificates_restart_required = true;
        $server->save();
    }
}
