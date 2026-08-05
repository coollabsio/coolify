<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteProxyDynamicConfiguration
{
    use AsAction;

    public function handle(Server $server, string $filename): bool
    {
        validateFilenameSafe($filename, 'proxy configuration filename');

        $file = $server->proxyPath()."/dynamic/{$filename}";
        $escapedFile = escapeshellarg($file);
        $result = instant_remote_process([
            "if test -f {$escapedFile}; then rm -f {$escapedFile} && echo deleted; else echo missing; fi",
        ], $server);

        $deleted = trim((string) $result) === 'deleted';
        if ($deleted && $server->proxyType() === ProxyTypes::CADDY->value) {
            $server->reloadCaddy();
        }

        return $deleted;
    }
}
