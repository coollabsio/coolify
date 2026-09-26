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

        $script = sprintf(
            'set -e; trap "rm -f -- %s" EXIT; umask 077; cat -- %s > %s; chmod 600 %s; mv -- %s %s',
            escapeshellarg($uploadPath),
            escapeshellarg($uploadPath),
            escapeshellarg($temporaryPath),
            escapeshellarg($temporaryPath),
            escapeshellarg($temporaryPath),
            escapeshellarg($path),
        );

        instant_remote_process(['sh -c '.escapeshellarg($script)], $server);
    }
}
