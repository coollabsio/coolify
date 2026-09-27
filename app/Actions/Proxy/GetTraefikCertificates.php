<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Services\TraefikAcmeService;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class GetTraefikCertificates
{
    use AsAction;

    public const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    public function __construct(private TraefikAcmeService $acmeService) {}

    /** @return array<int, array{id: string, resolver: string, main_domain: string, sans: array<int, string>, store: ?string, expires_at: ?string}> */
    public function handle(Server $server): array
    {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            return [];
        }

        $contents = $this->contents($server);

        return $contents === null ? [] : $this->acmeService->certificates($contents);
    }

    public function contents(Server $server): ?string
    {
        $path = escapeshellarg(rtrim($server->proxyPath(), '/').'/acme.json');
        $readLimit = self::MAX_FILE_SIZE_BYTES + 1;
        $tooLargeMarker = '__COOLIFY_ACME_FILE_TOO_LARGE__';
        $output = instant_remote_process([
            "if [ ! -f {$path} ]; then exit 0; elif [ \"\$(wc -c < {$path})\" -gt ".self::MAX_FILE_SIZE_BYTES." ]; then echo '{$tooLargeMarker}'; else head -c {$readLimit} {$path} | base64 | tr -d '\\n'; fi",
        ], $server, false);

        if ($output === null || trim($output) === '') {
            return null;
        }

        if (trim($output) === $tooLargeMarker) {
            throw new RuntimeException('The Traefik ACME file exceeds the 10 MiB size limit.');
        }

        $contents = base64_decode(trim($output), true);
        if ($contents === false || strlen($contents) > self::MAX_FILE_SIZE_BYTES) {
            throw new RuntimeException('The Traefik ACME file could not be read safely.');
        }

        return $contents;
    }
}
