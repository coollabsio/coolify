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

    /**
     * The certificates of acme.json. `in_use` is false when no route of the proxy and no resource
     * on the server uses a domain of the certificate, and null when Coolify could not check it.
     *
     * @return array<int, array{id: string, resolver: string, main_domain: string, sans: array<int, string>, store: ?string, expires_at: ?string, in_use: ?bool}>
     */
    public function handle(Server $server): array
    {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            return [];
        }

        $contents = $this->contents($server);
        $certificates = $contents === null ? [] : $this->acmeService->certificates($contents);
        if ($certificates === []) {
            return [];
        }

        $routing = $this->routingConfiguration($server);
        $hostnames = $routing === null ? null : $this->acmeService->hostnames($routing);

        return array_map(fn (array $certificate): array => [
            ...$certificate,
            'in_use' => $hostnames === null ? null : $this->acmeService->isInUse($certificate, $hostnames),
        ], $certificates);
    }

    /**
     * Text that names the domains the proxy can serve: the Traefik labels of all containers and
     * Swarm services, the proxy and dynamic configuration files, and the domains of the resources
     * on the server. Stopped resources have no containers, so their domains come from the database.
     */
    private function routingConfiguration(Server $server): ?string
    {
        $proxyPath = escapeshellarg(rtrim($server->proxyPath(), '/'));
        $script = implode('; ', [
            "docker ps -aq --filter label=traefik.enable=true | xargs -r docker inspect --format '{{json .Config.Labels}}' 2>/dev/null",
            "docker service ls -q 2>/dev/null | xargs -r docker service inspect --format '{{json .Spec.Labels}}' 2>/dev/null",
            "cat {$proxyPath}/docker-compose.yml {$proxyPath}/dynamic/*.yaml {$proxyPath}/dynamic/*.yml 2>/dev/null",
            'true',
        ]);

        try {
            $output = instant_remote_process(['sh -c '.escapeshellarg($script)], $server);
        } catch (\Throwable) {
            return null;
        }

        $applications = $server->applications();
        $previews = $server->previews();
        $domains = $applications->pluck('fqdn')
            ->concat($applications->pluck('docker_compose_domains'))
            ->concat($previews->pluck('fqdn'))
            ->concat($previews->pluck('docker_compose_domains'))
            ->concat($server->services()->with('applications')->get()->flatMap->applications->pluck('fqdn'))
            ->when($server->isLocalhost(), fn ($domains) => $domains->push(instanceSettings()->fqdn));

        return $output."\n".$domains->filter()->implode("\n");
    }

    public function contents(Server $server): ?string
    {
        $path = escapeshellarg(rtrim($server->proxyPath(), '/').'/acme.json');
        $readLimit = self::MAX_FILE_SIZE_BYTES + 1;
        $tooLargeMarker = '__COOLIFY_ACME_FILE_TOO_LARGE__';
        $script = "if [ ! -f {$path} ]; then exit 0; elif [ \"\$(wc -c < {$path})\" -gt ".self::MAX_FILE_SIZE_BYTES." ]; then echo '{$tooLargeMarker}'; else head -c {$readLimit} {$path} | base64 | tr -d '\\n'; fi";
        $output = instant_remote_process(['sh -c '.escapeshellarg($script)], $server, false);

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
