<?php

namespace App\Actions\Proxy;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class SaveTraefikAcmeFile
{
    use AsAction;

    public function handle(Server $server, string $contents): void
    {
        $path = rtrim($server->proxyPath(), '/').'/acme.json';
        $suffix = bin2hex(random_bytes(8));
        $temporaryPath = "{$path}.coolify-{$suffix}";
        $uploadPath = "/tmp/coolify-acme-{$suffix}.json";

        // Upload the file with scp: a certificate store can exceed the size limit of one shell argument.
        $localPath = tempnam(sys_get_temp_dir(), 'coolify-acme-');
        try {
            chmod($localPath, 0600);
            file_put_contents($localPath, $contents);
            instant_scp($localPath, $uploadPath, $server);
        } finally {
            @unlink($localPath);
        }

        // Keep a copy of the current file, so a change can be rolled back from the proxy page.
        $backup = ListTraefikAcmeBackups::backupCommands($server);
        $script = implode('; ', [
            'set -e',
            'trap '.escapeshellarg('rm -f -- '.escapeshellarg($uploadPath).' '.escapeshellarg($temporaryPath).' '.escapeshellarg($backup['temporary_path'])).' EXIT',
            'umask 077',
            'cat -- '.escapeshellarg($uploadPath).' > '.escapeshellarg($temporaryPath),
            'chmod 600 '.escapeshellarg($temporaryPath),
            $backup['script'],
            'mv -- '.escapeshellarg($temporaryPath).' '.escapeshellarg($path),
            ListTraefikAcmeBackups::pruneCommands($server),
        ]);

        instant_remote_process(['sh -c '.escapeshellarg($script)], $server);
    }
}
