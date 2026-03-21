<?php

namespace App\Traits;

use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Support\Collection;

trait ResolvesEdgeProxyServer
{
    protected function resolveEdgeProxyServerByTeamId(?int $teamId): ?Server
    {
        if (is_null($teamId)) {
            return null;
        }

        $edgeProxyServers = Server::query()
            ->where('team_id', $teamId)
            ->whereRelation('settings', 'is_master_domain_router_enabled', true)
            ->orderBy('id')
            ->get();

        if ($edgeProxyServers->count() > 1) {
            throw new \RuntimeException(sprintf(
                'Multiple master domain routers configured for team %d: server ids [%s]. Enable "Master Domain Router" on exactly one team server.',
                $teamId,
                $edgeProxyServers->pluck('id')->implode(', ')
            ));
        }

        return $edgeProxyServers->first();
    }

    protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
    {
        return $this->resolveEdgeProxyServerByTeamId($teamId);
    }

    /**
     * @return Collection<int, Server>
     */
    protected function resolveEdgeProxyServersByTeamId(?int $teamId): Collection
    {
        if (is_null($teamId)) {
            return collect();
        }

        return Server::query()
            ->where('team_id', $teamId)
            ->whereProxyType(ProxyTypes::TRAEFIK->value)
            ->orderBy('id')
            ->get();
    }

    protected function resolveRemoteHost(Server $deploymentServer): ?string
    {
        $candidates = [
            data_get($deploymentServer, 'proxy.wireguard_ip'),
            data_get($deploymentServer, 'proxy.wg_ip'),
            data_get($deploymentServer, 'proxy.tunnel_ip'),
            data_get($deploymentServer, 'proxy.tunnel_host'),
            data_get($deploymentServer, 'proxy.tunnel_domain'),
            data_get($deploymentServer, 'ip'),
        ];

        foreach ($candidates as $candidate) {
            $normalizedHost = $this->normalizeRemoteHost((string) $candidate);
            if (! is_null($normalizedHost)) {
                return $normalizedHost;
            }
        }

        return null;
    }

    protected function resolveTunnelHost(Server $deploymentServer): ?string
    {
        return $this->resolveRemoteHost($deploymentServer);
    }

    protected function normalizeRemoteHost(string $rawHost): ?string
    {
        $host = trim($rawHost);
        if ($host === '') {
            return null;
        }

        if (str_starts_with($host, 'http://') || str_starts_with($host, 'https://')) {
            $parsedHost = parse_url($host, PHP_URL_HOST);
            $host = is_string($parsedHost) ? $parsedHost : '';
        } elseif (str_contains($host, '/')) {
            $parsedHost = parse_url('http://'.$host, PHP_URL_HOST);
            $host = is_string($parsedHost) ? $parsedHost : '';
        }

        $host = trim($host, '[]');
        if ($host === '') {
            return null;
        }

        if (str_contains($host, ':') && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parsedHost = parse_url('http://'.$host, PHP_URL_HOST);
            $host = is_string($parsedHost) ? $parsedHost : '';
        }

        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return '['.$host.']';
        }

        return $host;
    }
}
