<?php

namespace App\Actions\Proxy;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteTraefikAcmeBackup
{
    use AsAction;

    public function handle(Server $server, string $backupName): void
    {
        $backupName = ListTraefikAcmeBackups::resolve($server, $backupName);
        $path = escapeshellarg(ListTraefikAcmeBackups::directory($server)."/{$backupName}");

        instant_remote_process(['sh -c '.escapeshellarg("rm -f -- {$path}")], $server);
    }
}
