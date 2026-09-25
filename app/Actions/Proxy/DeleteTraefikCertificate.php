<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Services\TraefikAcmeService;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class DeleteTraefikCertificate
{
    use AsAction;

    public function __construct(
        private GetTraefikCertificates $getTraefikCertificates,
        private TraefikAcmeService $acmeService,
    ) {}

    public function handle(Server $server, string $certificateId): void
    {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new RuntimeException('TLS certificates can only be managed for Traefik proxies.');
        }

        $contents = $this->getTraefikCertificates->contents($server);
        if ($contents === null) {
            throw new RuntimeException('The Traefik ACME file could not be found.');
        }

        $certificate = collect($this->acmeService->certificates($contents))
            ->firstWhere('id', $certificateId);
        if ($certificate === null) {
            throw new RuntimeException('The selected certificate could not be found.');
        }

        $updatedContents = $this->acmeService->deleteCertificate(
            $contents,
            $certificate['resolver'],
            $certificateId,
        );

        $path = rtrim($server->proxyPath(), '/').'/acme.json';
        $temporaryPath = $path.'.coolify-'.bin2hex(random_bytes(8));
        $encodedContents = base64_encode($updatedContents);
        $script = sprintf(
            'set -e; umask 077; printf %%s %s | base64 -d > %s; chmod 600 %s; mv -- %s %s',
            escapeshellarg($encodedContents),
            escapeshellarg($temporaryPath),
            escapeshellarg($temporaryPath),
            escapeshellarg($temporaryPath),
            escapeshellarg($path),
        );

        instant_remote_process(['sh -c '.escapeshellarg($script)], $server);
    }
}
