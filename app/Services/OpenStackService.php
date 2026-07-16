<?php

namespace App\Services;

use App\Exceptions\RateLimitException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the subset of the OpenStack API that Coolify needs to
 * provision and manage servers: Keystone (identity), Nova (compute),
 * Glance (image) and Neutron (network).
 *
 * Authentication uses a Keystone v3 application credential, which is already
 * scoped to a project, so no additional scope block is required. The resulting
 * token and service catalog are cached on the instance for the lifetime of the
 * request.
 *
 * @phpstan-type OpenStackCredentials array{auth_url: string, application_credential_id: string, application_credential_secret: string, region?: string|null}
 */
class OpenStackService
{
    private string $authUrl;

    private string $applicationCredentialId;

    private string $applicationCredentialSecret;

    private ?string $region;

    private ?string $token = null;

    /**
     * @var array<int, array{type: string, name?: string, endpoints: array<int, array<string, mixed>>}>
     */
    private array $catalog = [];

    /**
     * @param  OpenStackCredentials  $credentials
     */
    public function __construct(array $credentials)
    {
        $this->authUrl = rtrim((string) ($credentials['auth_url'] ?? ''), '/');
        $this->applicationCredentialId = (string) ($credentials['application_credential_id'] ?? '');
        $this->applicationCredentialSecret = (string) ($credentials['application_credential_secret'] ?? '');
        $this->region = $credentials['region'] ?? null;
    }

    /**
     * Authenticate against Keystone and cache the token + service catalog.
     * Returns the issued token string.
     */
    public function authenticate(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $response = Http::acceptJson()
            ->timeout(30)
            ->retry(3, fn (int $attempt) => $attempt * 100, throw: false)
            ->post($this->authUrl.'/auth/tokens', [
                'auth' => [
                    'identity' => [
                        'methods' => ['application_credential'],
                        'application_credential' => [
                            'id' => $this->applicationCredentialId,
                            'secret' => $this->applicationCredentialSecret,
                        ],
                    ],
                ],
            ]);

        if (! $response->successful()) {
            $this->throwForResponse($response, 'authentication');
        }

        $token = $response->header('X-Subject-Token');

        if (! $token) {
            throw new \Exception('OpenStack authentication succeeded but no token was returned.');
        }

        $this->token = $token;
        $this->catalog = $response->json('token.catalog', []);

        return $this->token;
    }

    /**
     * Resolve a base URL for a given service type (e.g. compute, image, network,
     * identity) from the catalog, preferring the public interface and the
     * configured region when set.
     */
    public function endpointFor(string $serviceType): string
    {
        $this->authenticate();

        foreach ($this->catalog as $service) {
            if (($service['type'] ?? null) !== $serviceType) {
                continue;
            }

            $endpoints = collect($service['endpoints'] ?? [])
                ->filter(fn ($endpoint) => ($endpoint['interface'] ?? null) === 'public');

            if ($this->region) {
                $regionMatch = $endpoints->first(fn ($endpoint) => ($endpoint['region_id'] ?? $endpoint['region'] ?? null) === $this->region);

                if ($regionMatch) {
                    return rtrim($regionMatch['url'], '/');
                }
            }

            $first = $endpoints->first();

            if ($first) {
                return rtrim($first['url'], '/');
            }
        }

        throw new \Exception("OpenStack service '{$serviceType}' not found in the catalog for this project/region.");
    }

    /**
     * Availability zones act as the "location" selector when booting a server.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAvailabilityZones(): array
    {
        $zones = $this->request('get', 'compute', '/os-availability-zone')['availabilityZoneInfo'] ?? [];

        return collect($zones)
            ->filter(fn ($zone) => ($zone['zoneState']['available'] ?? true) === true)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFlavors(): array
    {
        return $this->request('get', 'compute', '/flavors/detail')['flavors'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getImages(): array
    {
        $images = [];
        $path = '/v2/images?status=active&limit=100';

        // Glance paginates via a "next" marker in the response body.
        do {
            $response = $this->request('get', 'image', $path);
            $images = array_merge($images, $response['images'] ?? []);
            $path = $response['next'] ?? null;
        } while ($path !== null);

        return $images;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getNetworks(): array
    {
        return $this->request('get', 'network', '/v2.0/networks')['networks'] ?? [];
    }

    /**
     * External networks are the ones a floating IP can be allocated from.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getExternalNetworks(): array
    {
        return collect($this->getNetworks())
            ->filter(fn ($network) => ($network['router:external'] ?? false) === true)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSecurityGroups(): array
    {
        return $this->request('get', 'network', '/v2.0/security-groups')['security_groups'] ?? [];
    }

    /**
     * Find or create a security group by name and make sure it has the given
     * ingress rules. Returns the security group name (used when booting).
     *
     * OpenStack's default security group only allows traffic from within the
     * same group, so a new instance is unreachable over SSH/HTTP until a group
     * like this is attached — Hetzner opens these ports by default.
     *
     * @param  array<int, array{protocol: string, port?: int}>  $ingressRules
     */
    public function ensureSecurityGroup(string $name, string $description, array $ingressRules): string
    {
        $group = collect($this->getSecurityGroups())->firstWhere('name', $name);

        if (! $group) {
            $group = $this->request('post', 'network', '/v2.0/security-groups', [
                'security_group' => ['name' => $name, 'description' => $description],
            ])['security_group'];
        }

        $existing = collect($group['security_group_rules'] ?? []);

        foreach ($ingressRules as $rule) {
            $port = $rule['port'] ?? null;

            $alreadyPresent = $existing->contains(function ($r) use ($rule, $port) {
                return ($r['direction'] ?? null) === 'ingress'
                    && ($r['protocol'] ?? null) === $rule['protocol']
                    && (int) ($r['port_range_min'] ?? 0) === (int) $port
                    && (int) ($r['port_range_max'] ?? 0) === (int) $port;
            });

            if ($alreadyPresent) {
                continue;
            }

            $body = [
                'security_group_id' => $group['id'],
                'direction' => 'ingress',
                'ethertype' => 'IPv4',
                'protocol' => $rule['protocol'],
                'remote_ip_prefix' => '0.0.0.0/0',
            ];

            if ($port !== null) {
                $body['port_range_min'] = $port;
                $body['port_range_max'] = $port;
            }

            try {
                $this->request('post', 'network', '/v2.0/security-group-rules', ['security_group_rule' => $body]);
            } catch (\Throwable $e) {
                // Ignore "rule already exists" style conflicts.
            }
        }

        return $group['id'];
    }

    /**
     * Ensure a "coolify" security group exists that allows the ports Coolify
     * needs to manage a server and serve apps (SSH, HTTP, HTTPS, ping), and
     * return its ID.
     */
    public function ensureCoolifySecurityGroup(): string
    {
        return $this->ensureSecurityGroup('coolify', 'Managed by Coolify: SSH, HTTP, HTTPS, ICMP', [
            ['protocol' => 'tcp', 'port' => 22],
            ['protocol' => 'tcp', 'port' => 80],
            ['protocol' => 'tcp', 'port' => 443],
            ['protocol' => 'icmp'],
        ]);
    }

    /**
     * Attach a security group to an instance by updating its Neutron port,
     * keeping the port's existing groups (e.g. default).
     *
     * This works by security-group ID, so it is unaffected by the project
     * containing duplicate security-group names — unlike Nova's
     * addSecurityGroup action, which resolves by name and fails when duplicate
     * names exist anywhere in the project.
     */
    public function attachSecurityGroupToPort(string $portId, string $securityGroupId): void
    {
        $port = $this->request('get', 'network', "/v2.0/ports/{$portId}")['port'] ?? [];
        $groups = $port['security_groups'] ?? [];

        if (in_array($securityGroupId, $groups, true)) {
            return;
        }

        $groups[] = $securityGroupId;

        $this->request('put', 'network', "/v2.0/ports/{$portId}", [
            'port' => ['security_groups' => array_values($groups)],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getKeypairs(): array
    {
        $keypairs = $this->request('get', 'compute', '/os-keypairs')['keypairs'] ?? [];

        // Nova wraps each entry in a "keypair" key.
        return collect($keypairs)
            ->map(fn ($entry) => $entry['keypair'] ?? $entry)
            ->values()
            ->all();
    }

    public function findKeypairByName(string $name): ?array
    {
        foreach ($this->getKeypairs() as $keypair) {
            if (($keypair['name'] ?? null) === $name) {
                return $keypair;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadKeypair(string $name, string $publicKey): array
    {
        $response = $this->request('post', 'compute', '/os-keypairs', [
            'keypair' => [
                'name' => $name,
                'public_key' => $publicKey,
            ],
        ]);

        return $response['keypair'] ?? [];
    }

    /**
     * Boot a server. Expected params: name, imageRef, flavorRef, networkId,
     * key_name, optional availabilityZone and userData (raw cloud-init string).
     *
     * When volumeSize (GB) is provided the instance boots from a new volume
     * created from the image (block device mapping). This is required for
     * "diskless" flavors (root disk = 0), which are common on SCS clouds, and
     * the volume is deleted together with the server.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createServer(array $params): array
    {
        $server = [
            'name' => $params['name'],
            'flavorRef' => $params['flavorRef'],
            'networks' => [
                ['uuid' => $params['networkId']],
            ],
        ];

        if (! empty($params['volumeSize'])) {
            // Boot from a volume created from the image.
            $server['block_device_mapping_v2'] = [[
                'boot_index' => 0,
                'uuid' => $params['imageRef'],
                'source_type' => 'image',
                'destination_type' => 'volume',
                'volume_size' => (int) $params['volumeSize'],
                'delete_on_termination' => true,
            ]];
        } else {
            $server['imageRef'] = $params['imageRef'];
        }

        if (! empty($params['key_name'])) {
            $server['key_name'] = $params['key_name'];
        }

        if (! empty($params['availabilityZone'])) {
            $server['availability_zone'] = $params['availabilityZone'];
        }

        if (! empty($params['userData'])) {
            $server['user_data'] = base64_encode($params['userData']);
        }

        ray('OpenStack createServer request', ['server' => $server]);

        $response = $this->request('post', 'compute', '/servers', ['server' => $server]);

        ray('OpenStack createServer response', ['response' => $response]);

        return $response['server'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getServer(string $serverId): array
    {
        return $this->request('get', 'compute', "/servers/{$serverId}")['server'] ?? [];
    }

    public function deleteServer(string $serverId): void
    {
        $this->request('delete', 'compute', "/servers/{$serverId}");
    }

    /**
     * Find the Neutron port bound to a server (needed to associate a floating IP).
     */
    public function getServerPortId(string $serverId): ?string
    {
        $ports = $this->request('get', 'network', "/v2.0/ports?device_id={$serverId}")['ports'] ?? [];

        return $ports[0]['id'] ?? null;
    }

    /**
     * Allocate a floating IP from an external network, optionally associating it
     * with a port in the same call.
     *
     * @return array<string, mixed>
     */
    public function allocateFloatingIp(string $externalNetworkId, ?string $portId = null): array
    {
        $floatingip = ['floating_network_id' => $externalNetworkId];

        if ($portId) {
            $floatingip['port_id'] = $portId;
        }

        return $this->request('post', 'network', '/v2.0/floatingips', [
            'floatingip' => $floatingip,
        ])['floatingip'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function associateFloatingIp(string $floatingIpId, string $portId): array
    {
        return $this->request('put', 'network', "/v2.0/floatingips/{$floatingIpId}", [
            'floatingip' => ['port_id' => $portId],
        ])['floatingip'] ?? [];
    }

    public function releaseFloatingIp(string $floatingIpId): void
    {
        $this->request('delete', 'network', "/v2.0/floatingips/{$floatingIpId}");
    }

    /**
     * Extract the first fixed IPv4 address a server received from its network.
     */
    public function getServerFixedIp(array $server): ?string
    {
        foreach ($server['addresses'] ?? [] as $addresses) {
            foreach ($addresses as $address) {
                if (($address['OS-EXT-IPS:type'] ?? 'fixed') === 'fixed' && ($address['version'] ?? 4) === 4) {
                    return $address['addr'] ?? null;
                }
            }
        }

        return null;
    }

    /**
     * Perform an authenticated request against a catalog service.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function request(string $method, string $serviceType, string $endpoint, array $data = []): array
    {
        $baseUrl = $this->endpointFor($serviceType);

        $request = $this->client();

        $response = in_array($method, ['get', 'delete'], true)
            ? $request->{$method}($baseUrl.$endpoint)
            : $request->{$method}($baseUrl.$endpoint, $data);

        if (! $response->successful()) {
            $this->throwForResponse($response, "{$serviceType} API");
        }

        // DELETE responses are typically empty (204).
        return $response->json() ?? [];
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'X-Auth-Token' => $this->authenticate(),
        ])
            ->acceptJson()
            ->timeout(30)
            ->retry(3, fn (int $attempt) => $attempt * 100, throw: false);
    }

    private function throwForResponse(\Illuminate\Http\Client\Response $response, string $context): never
    {
        if ($response->status() === 429) {
            $retryAfter = $response->header('Retry-After');

            throw new RateLimitException(
                'OpenStack rate limit exceeded. Please try again later.',
                $retryAfter !== null ? (int) $retryAfter : null
            );
        }

        $message = $response->json('error.message')
            ?? $response->json('message')
            ?? $response->json('itemNotFound.message')
            ?? $response->body();

        throw new \Exception("OpenStack {$context} error: ".($message ?: 'Unknown error'));
    }
}
