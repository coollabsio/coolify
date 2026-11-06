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

    /**
     * Deletes the certificates in one write, so acme.json gets one backup.
     *
     * @param  array<int, string>  $certificateIds
     * @return array<int, array{id: string, resolver: string, main_domain: string, sans: array<int, string>, store: ?string, expires_at: ?string}>
     */
    public function handle(Server $server, array $certificateIds): array
    {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new RuntimeException('TLS certificates can only be managed for Traefik proxies.');
        }

        $contents = $this->getTraefikCertificates->contents($server);
        if ($contents === null) {
            throw new RuntimeException('The Traefik ACME file could not be found.');
        }

        $certificates = collect($this->acmeService->certificates($contents))
            ->whereIn('id', $certificateIds)
            ->values()
            ->all();

        SaveTraefikAcmeFile::run($server, $this->acmeService->deleteCertificates($contents, $certificateIds));

        // Traefik keeps loaded certificates in memory until it restarts.
        $server->proxy->certificates_restart_required = true;
        $server->save();

        return $certificates;
    }
}
