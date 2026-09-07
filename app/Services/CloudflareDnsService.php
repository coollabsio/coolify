<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Spatie\Url\Url;

class CloudflareDnsService
{
    private string $baseUrl = 'https://api.cloudflare.com/client/v4';

    public function __construct(private readonly string $token) {}

    public static function forServer(Server $server): ?self
    {
        $server->loadMissing('settings.cloudflareDnsToken');

        if (! $server->settings?->cloudflare_dns_enabled || ! $server->settings?->cloudflareDnsToken) {
            return null;
        }

        return new self($server->settings->cloudflareDnsToken->token);
    }

    /**
     * @return array{valid: bool, error: string|null}
     */
    public function validateToken(): array
    {
        try {
            $verifyResponse = $this->request('get', '/user/tokens/verify');
            if (! $verifyResponse->successful() || $verifyResponse->json('success') !== true) {
                return ['valid' => false, 'error' => 'Invalid Cloudflare token.'];
            }

            $zonesResponse = $this->request('get', '/zones', ['per_page' => 1]);
            if (! $zonesResponse->successful() || $zonesResponse->json('success') !== true) {
                return ['valid' => false, 'error' => 'Cloudflare token must be able to read zones.'];
            }

            return ['valid' => true, 'error' => null];
        } catch (\Throwable) {
            return ['valid' => false, 'error' => 'Failed to validate token with Cloudflare API.'];
        }
    }

    /**
     * @param  array<int, string>  $domains
     * @return array<int, array{domain: string, host: string, type: string, content: string, action: string}>
     */
    public function ensureRecordsForDomains(Server $server, array $domains, bool $proxied = false): array
    {
        $ip = $this->serverIp($server);
        $type = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';

        return collect($domains)
            ->filter()
            ->map(fn (string $domain) => trim($domain))
            ->filter()
            ->unique()
            ->map(fn (string $domain) => $this->ensureRecord($domain, $type, $ip, $proxied))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function domainsForApplication(Application $application, ?ApplicationPreview $preview = null): array
    {
        $domains = collect();

        $this->pushCommaSeparatedDomains($domains, $application->fqdn);
        $this->pushDockerComposeDomains($domains, $application->docker_compose_domains);

        if ($preview) {
            $this->pushCommaSeparatedDomains($domains, $preview->fqdn);
            $this->pushDockerComposeDomains($domains, $preview->docker_compose_domains);
        }

        return $domains->filter()->unique()->values()->all();
    }

    /**
     * @return array{domain: string, host: string, type: string, content: string, action: string}
     */
    private function ensureRecord(string $domain, string $type, string $content, bool $proxied): array
    {
        $host = $this->hostFromDomain($domain);
        $zone = $this->findZoneForHost($host);
        $existingRecord = $this->findRecord($zone['id'], $type, $host);
        $payload = [
            'type' => $type,
            'name' => $host,
            'content' => $content,
            'ttl' => 1,
            'proxied' => $proxied,
        ];

        if ($existingRecord === null) {
            $response = $this->request('post', "/zones/{$zone['id']}/dns_records", $payload);
            $this->throwIfFailed($response, "Unable to create Cloudflare DNS record for {$host}.");

            return compact('domain', 'host', 'type', 'content') + ['action' => 'created'];
        }

        if (
            data_get($existingRecord, 'content') === $content &&
            (bool) data_get($existingRecord, 'proxied') === $proxied
        ) {
            return compact('domain', 'host', 'type', 'content') + ['action' => 'unchanged'];
        }

        $response = $this->request('patch', "/zones/{$zone['id']}/dns_records/{$existingRecord['id']}", $payload);
        $this->throwIfFailed($response, "Unable to update Cloudflare DNS record for {$host}.");

        return compact('domain', 'host', 'type', 'content') + ['action' => 'updated'];
    }

    /**
     * @return array{id: string, name: string}
     */
    private function findZoneForHost(string $host): array
    {
        $labels = explode('.', $host);

        while (count($labels) >= 2) {
            $zoneName = implode('.', $labels);
            $response = $this->request('get', '/zones', [
                'name' => $zoneName,
                'status' => 'active',
                'per_page' => 1,
            ]);
            $this->throwIfFailed($response, "Unable to find Cloudflare zone for {$host}.");

            $zone = collect($response->json('result', []))->first();
            if ($zone) {
                return [
                    'id' => $zone['id'],
                    'name' => $zone['name'],
                ];
            }

            array_shift($labels);
        }

        throw new \RuntimeException("No active Cloudflare zone found for {$host}.");
    }

    private function findRecord(string $zoneId, string $type, string $host): ?array
    {
        $response = $this->request('get', "/zones/{$zoneId}/dns_records", [
            'type' => $type,
            'name' => $host,
            'per_page' => 100,
        ]);
        $this->throwIfFailed($response, "Unable to list Cloudflare DNS records for {$host}.");

        return collect($response->json('result', []))->first();
    }

    private function request(string $method, string $endpoint, array $data = []): Response
    {
        return Http::withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->{$method}($this->baseUrl.$endpoint, $data);
    }

    private function throwIfFailed(Response $response, string $message): void
    {
        if ($response->successful() && $response->json('success') === true) {
            return;
        }

        $error = collect($response->json('errors', []))->pluck('message')->filter()->implode(' ');

        throw new \RuntimeException(trim($message.' '.$error));
    }

    private function hostFromDomain(string $domain): string
    {
        $domain = trim($domain);
        $url = Url::fromString(str($domain)->contains('://') ? $domain : 'http://'.$domain, ['http', 'https']);

        return strtolower($url->getHost());
    }

    private function serverIp(Server $server): string
    {
        $ip = $server->ip;

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \RuntimeException("Server {$server->name} does not have a valid public IP address.");
        }

        return $ip;
    }

    private function pushCommaSeparatedDomains(Collection $domains, ?string $domainString): void
    {
        if (blank($domainString)) {
            return;
        }

        str($domainString)->explode(',')->each(function (string $domain) use ($domains) {
            $domains->push(trim($domain));
        });
    }

    private function pushDockerComposeDomains(Collection $domains, ?string $dockerComposeDomains): void
    {
        if (blank($dockerComposeDomains)) {
            return;
        }

        collect(json_decode($dockerComposeDomains, true) ?: [])
            ->pluck('domain')
            ->each(fn (?string $domain) => $this->pushCommaSeparatedDomains($domains, $domain));
    }
}
