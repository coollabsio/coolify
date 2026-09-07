<?php

namespace App\Livewire\Concerns;

use App\Support\DnsRecordHints;
use App\Support\DomainConnect\CloudflareDomainConnect;
use Illuminate\Support\Js;

trait InteractsWithCloudflareDomainConnect
{
    public bool $showCloudflareAutoconfigureModal = false;

    public bool $showDnsRecordsModal = false;

    public function domainConnectAvailable(): bool
    {
        return app(CloudflareDomainConnect::class)->isAvailable();
    }

    public function openCloudflareAutoconfigureModal(): void
    {
        $this->authorizeUpdateForDomainConnect();

        if (! $this->domainConnectAvailable()) {
            $this->dispatch('error', 'Automated DNS configuration is only available on Coolify Cloud when Domain Connect is configured.');

            return;
        }

        if ($this->allDomainHostnames() === []) {
            $this->dispatch('error', 'Add at least one domain before configuring DNS on Cloudflare.');

            return;
        }

        $this->showCloudflareAutoconfigureModal = true;
    }

    public function closeCloudflareAutoconfigureModal(): void
    {
        $this->showCloudflareAutoconfigureModal = false;
    }

    public function applyCloudflareAutoconfigure(): void
    {
        $this->authorizeUpdateForDomainConnect();

        $domainConnect = app(CloudflareDomainConnect::class);

        if (! $domainConnect->isAvailable()) {
            $this->dispatch(
                'error',
                'Automated DNS configuration is only available on Coolify Cloud when a Domain Connect private key is configured.'
            );

            return;
        }

        $ip = $this->serverIpForDomainConnect();
        if (blank($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->dispatch(
                'error',
                'A resolvable server IP is required before autoconfiguring DNS. Set a public IP on the server (or instance settings for localhost).'
            );

            return;
        }

        $hostnames = $this->allDomainHostnames();
        if ($hostnames === []) {
            $this->dispatch('error', 'Add at least one domain before configuring DNS on Cloudflare.');

            return;
        }

        $urls = [];

        try {
            foreach ($hostnames as $hostname) {
                $parts = CloudflareDomainConnect::splitHostname($hostname);
                $urls[] = $domainConnect->buildHostingApplyUrl(
                    domain: $parts['domain'],
                    ip: $ip,
                    host: $parts['host'] !== '' ? $parts['host'] : null,
                );
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());

            return;
        }

        $urls = array_values(array_unique($urls));
        $this->showCloudflareAutoconfigureModal = false;

        $openScript = collect($urls)
            ->map(fn (string $url) => 'window.open('.Js::from($url).', "_blank", "noopener,noreferrer");')
            ->implode("\n");

        $this->js($openScript);
    }

    public function openDnsRecordsModal(): void
    {
        $this->authorizeUpdateForDomainConnect();
        $this->showDnsRecordsModal = true;
    }

    public function closeDnsRecordsModal(): void
    {
        $this->showDnsRecordsModal = false;
    }

    public function recheckDnsRecordsInModal(): void
    {
        $this->authorizeUpdateForDomainConnect();
        $this->checkAllDns();
    }

    /**
     * DNS records for hosts that still need attention (not already resolving correctly).
     *
     * @return array<int, array{type: string, name: string, value: string}>
     */
    public function dnsRecordHints(): array
    {
        [$ipv4, $ipv6] = $this->serverIpsForDnsHints();

        return DnsRecordHints::forHostnames($this->allDomainHostnames(onlyNeedingDns: true), $ipv4, $ipv6);
    }

    public function dnsRecordsCopyText(): string
    {
        return DnsRecordHints::toCopyText($this->dnsRecordHints());
    }

    /**
     * Unique hostnames from domain rows (and optional form inputs).
     *
     * @param  bool  $onlyNeedingDns  When true, skip hosts whose DNS status is already ok.
     * @return array<int, string>
     */
    protected function allDomainHostnames(bool $onlyNeedingDns = false): array
    {
        $hosts = [];
        $workingHosts = [];

        foreach ($this->domainRows ?? [] as $row) {
            $url = $row['url'] ?? null;
            if (! is_string($url) || $url === '') {
                continue;
            }
            $host = parse_url($url, PHP_URL_HOST);
            if (! is_string($host) || $host === '') {
                continue;
            }
            $host = strtolower($host);

            // Working configured domains: DNS already points at this server.
            if (($row['dns_status'] ?? null) === 'ok' && ! ($row['is_suggested'] ?? false)) {
                $workingHosts[$host] = true;
            }

            $hosts[] = $host;
        }

        if (filled($this->newDomain ?? null)) {
            $candidate = $this->normalizeHostnameInput((string) $this->newDomain);
            if ($candidate !== '') {
                $hosts[] = strtolower($candidate);
            }
        }

        if (filled($this->editingDomain ?? null) && ($this->showEditDomainModal ?? false)) {
            $candidate = $this->normalizeHostnameInput((string) $this->editingDomain);
            if ($candidate !== '') {
                $hosts[] = strtolower($candidate);
            }
        }

        $hosts = array_values(array_unique(array_filter($hosts)));

        if ($onlyNeedingDns) {
            $hosts = array_values(array_filter(
                $hosts,
                fn (string $host) => ! isset($workingHosts[$host])
            ));
        }

        sort($hosts);

        return $hosts;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    protected function serverIpsForDnsHints(): array
    {
        $ipv4 = null;
        $ipv6 = null;

        $ip = $this->serverIpForDomainConnect();
        if (filled($ip)) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $ipv4 = $ip;
            } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $ipv6 = $ip;
            }
        }

        // Prefer instance public IPv6 when the destination IP is IPv4-only (and vice versa).
        try {
            $settings = instanceSettings();
            $publicV4 = data_get($settings, 'public_ipv4');
            $publicV6 = data_get($settings, 'public_ipv6');
            if ($ipv4 === null && is_string($publicV4) && filter_var($publicV4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ipv4 = $publicV4;
            }
            if ($ipv6 === null && is_string($publicV6) && filter_var($publicV6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $ipv6 = $publicV6;
            }
        } catch (\Throwable) {
            //
        }

        return [$ipv4, $ipv6];
    }

    protected function defaultDnsHostname(): string
    {
        $hosts = $this->allDomainHostnames();

        return $hosts[0] ?? '';
    }

    protected function normalizeHostnameInput(string $hostname): string
    {
        $hostname = trim($hostname);
        $hostname = preg_replace('#^https?://#i', '', $hostname) ?? $hostname;
        $hostname = explode('/', $hostname)[0] ?? $hostname;
        $hostname = explode(':', $hostname)[0] ?? $hostname;

        return rtrim($hostname, '.');
    }

    protected function serverIpForDomainConnect(): ?string
    {
        if (filled($this->serverIp) && filter_var($this->serverIp, FILTER_VALIDATE_IP) !== false) {
            return $this->serverIp;
        }

        return null;
    }

    abstract protected function authorizeUpdateForDomainConnect(): void;
}
