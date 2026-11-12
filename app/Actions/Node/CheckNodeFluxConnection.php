<?php

namespace App\Actions\Node;

use App\Actions\Sentinel\ResolveFluxPublicUrl;
use App\Actions\Sentinel\ResolveFluxTrustBundle;
use App\Models\Node;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Opens a TLS connection from the Node to the Flux URL with the trust bundle of Sentinel, before Sentinel is installed.
 */
class CheckNodeFluxConnection
{
    use AsAction;

    public const FAILURE_PREFIX = 'The server cannot reach Flux at';

    public function handle(Node $node): void
    {
        $url = (new ResolveFluxPublicUrl)->resolve();
        $bundle = ResolveFluxTrustBundle::run();
        $script = sprintf(<<<'SH'
set -eu
ca="$(mktemp)"
trap 'rm -f "$ca"' EXIT
printf '%%s' %s | base64 -d > "$ca"
if curl -sS -o /dev/null --connect-timeout 10 --max-time 15 --cacert "$ca" %s 2>&1; then echo flux-tls-ok; fi
SH, escapeshellarg(base64_encode($bundle['certificate_pem'])), escapeshellarg($url.'/'));

        $output = (string) instant_remote_process([InstallSentinel::hostCommand($node, $script)], $node, timeout: 60, disableMultiplexing: true);
        if (! str_contains($output, 'flux-tls-ok')) {
            $host = parse_url($url, PHP_URL_HOST);
            $port = parse_url($url, PHP_URL_PORT);
            throw new RuntimeException(self::FAILURE_PREFIX." {$host}:{$port}. Allow TCP port {$port} from this server to Coolify, or change the Flux URL in Settings > Advanced. ".strtok(trim($output), "\n"));
        }
    }
}
