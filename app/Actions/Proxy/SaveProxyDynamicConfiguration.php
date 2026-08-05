<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class SaveProxyDynamicConfiguration
{
    use AsAction;

    public function handle(Server $server, string $filename, string $configuration, bool $create): bool
    {
        validateFilenameSafe($filename, 'proxy configuration filename');

        $dynamicPath = $server->proxyPath().'/dynamic';
        $temporaryFilename = ".{$filename}.".Str::lower(Str::random(12)).'.tmp';
        $escapedDynamicPath = escapeshellarg($dynamicPath);
        $escapedFile = escapeshellarg("{$dynamicPath}/{$filename}");
        $escapedTemporaryFile = escapeshellarg("{$dynamicPath}/{$temporaryFilename}");
        $encodedConfiguration = base64_encode($configuration);

        $saveCommand = $create
            ? "if ln {$escapedTemporaryFile} {$escapedFile} 2>/dev/null; then rm -f {$escapedTemporaryFile}; echo saved; else rm -f {$escapedTemporaryFile}; echo exists; fi"
            : "if test -f {$escapedFile}; then mv -f {$escapedTemporaryFile} {$escapedFile} && echo saved; else rm -f {$escapedTemporaryFile}; echo missing; fi";

        $result = instant_remote_process([
            "mkdir -p {$escapedDynamicPath}",
            "printf '%s' '{$encodedConfiguration}' | base64 -d > {$escapedTemporaryFile}",
            $saveCommand,
        ], $server);

        $saved = trim((string) $result) === 'saved';
        if ($saved && $server->proxyType() === ProxyTypes::CADDY->value) {
            $server->reloadCaddy();
        }

        return $saved;
    }
}
