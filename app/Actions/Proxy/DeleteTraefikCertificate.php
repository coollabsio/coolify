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

    /** @return array{id: string, resolver: string, main_domain: string, sans: array<int, string>, store: ?string, expires_at: ?string} */
    public function handle(Server $server, string $certificateId): array
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

        SaveTraefikAcmeFile::run($server, $updatedContents);

        // Traefik keeps loaded certificates in memory until it restarts.
        $server->proxy->certificates_restart_required = true;
        $server->save();

        return $certificate;
    }
}
