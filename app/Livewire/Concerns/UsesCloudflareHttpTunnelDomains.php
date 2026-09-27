<?php

namespace App\Livewire\Concerns;

use App\Models\Server;
use App\Support\CloudflareHttpTunnel;
use App\Support\DomainUrlParts;

trait UsesCloudflareHttpTunnelDomains
{
    protected function cloudflareHttpTunnelServer(): ?Server
    {
        if (isset($this->application)) {
            return $this->application->destination?->server;
        }

        if (isset($this->service)) {
            return $this->service->server ?? $this->service->destination?->server;
        }

        if (isset($this->preview)) {
            return $this->preview->application?->destination?->server;
        }

        return null;
    }

    public function usesCloudflareHttpTunnel(): bool
    {
        return (bool) $this->cloudflareHttpTunnelServer()?->isCloudflareHttpTunnel();
    }

    public function defaultDomainScheme(): string
    {
        return $this->usesCloudflareHttpTunnel() ? 'http' : 'https';
    }

    public function cloudflareHttpTunnelCname(): ?string
    {
        $server = $this->cloudflareHttpTunnelServer();
        if (! $server) {
            return null;
        }

        return CloudflareHttpTunnel::fromServer($server);
    }

    public function emptyDomainParts(): array
    {
        return DomainUrlParts::empty($this->defaultDomainScheme());
    }

    protected function dnsProviderRecordContent(): ?string
    {
        if ($this->usesCloudflareHttpTunnel()) {
            return $this->cloudflareHttpTunnelCname();
        }

        if (filled($this->serverIp ?? null) && filter_var($this->serverIp, FILTER_VALIDATE_IP) !== false) {
            return $this->serverIp;
        }

        return null;
    }

    protected function dnsCheckExpectedTarget(): ?string
    {
        if ($this->usesCloudflareHttpTunnel()) {
            return $this->cloudflareHttpTunnelCname();
        }

        $server = $this->cloudflareHttpTunnelServer();
        if (! $server) {
            return $this->serverIp ?? null;
        }

        return serverDnsTargetIp($server) ?? $server->ip;
    }
}
