<?php

namespace App\Actions\Node;

use App\Models\NodeCluster;
use App\Models\NodeStack;
use App\Models\NodeWorkload;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Builds the HTTP routes that every ingress Node of a cluster serves: one route per domain and path
 * of each workload that runs on a Node of the cluster, plus its www redirects. Routes reference the
 * workload UUID: Sentinel resolves the healthy containers of a route from the `workload_endpoints`
 * it reads from Corrosion, keyed by the `coolify.workload` container label.
 */
class BuildNodeClusterIngressRoutes
{
    use AsAction;

    public const MAX_ROUTES = 10_000;

    public const MAX_NAMES = 10_000;

    public const NAMESPACE = 'default';

    /**
     * Without `ingress.routes.v2` on every Node, each host gets one route with the old fields only.
     *
     * @return list<array{host: string, workload_id: string, namespace: string, port: int, path?: string, redirect_host?: string, http_only?: bool, allow_http?: bool, temporary_redirect?: bool, noindex?: bool, strip_path?: bool}>
     */
    public function handle(NodeCluster $cluster): array
    {
        $routes = [];
        $hostOwners = [];
        $redirects = [];
        foreach (self::routedWorkloads($cluster) as $workload) {
            $flags = ['allow_http' => ! $workload->force_https, 'temporary_redirect' => ! $workload->permanent_redirect];
            $schemes = [];
            foreach ($workload->domain_routes as $route) {
                $host = (string) ($route['host'] ?? '');
                $path = (string) ($route['path'] ?? '');
                // Sentinel rejects the whole route table for one invalid route, so skip it here.
                if (! self::validHost($host) || ! self::validPath($path)) {
                    continue;
                }
                // Domains are unique across workloads. Keep the first workload of a host if old data disagrees.
                if (($hostOwners[$host] ??= $workload->id) !== $workload->id) {
                    continue;
                }
                $schemes[$host] = $route['scheme'] ?? 'https';
                $routes["{$host}|{$path}"] ??= [
                    'host' => $host,
                    'workload_id' => $workload->uuid,
                    'namespace' => self::NAMESPACE,
                    'port' => (int) $route['port'],
                    'path' => $path,
                    'redirect_host' => '',
                    'http_only' => $schemes[$host] === 'http',
                    ...$flags,
                    'noindex' => (bool) ($route['noindex'] ?? false),
                    'strip_path' => $path !== '',
                ];
            }
            foreach (self::wwwRedirects(array_keys($schemes), $workload->www_redirect) as $from => $to) {
                $redirects[$from] ??= [
                    'host' => $from,
                    'workload_id' => '',
                    'namespace' => '',
                    'port' => 0,
                    'path' => '',
                    'redirect_host' => $to,
                    'http_only' => $schemes[$to] === 'http',
                    ...$flags,
                    'noindex' => false,
                    'strip_path' => false,
                ];
            }
        }
        // A redirect host must have no other route, so a host that serves an application wins.
        $redirects = array_filter($redirects, fn (array $redirect): bool => ! isset($hostOwners[$redirect['host']]));
        $routes = $cluster->supportsIngressRoutesV2()
            ? [...array_values($routes), ...array_values($redirects)]
            : self::legacyRoutes($routes);
        if (count($routes) > self::MAX_ROUTES) {
            throw new RuntimeException('The cluster has more than '.number_format(self::MAX_ROUTES).' domains. Remove domains to apply ingress.');
        }

        return $routes;
    }

    /**
     * `www` redirects `X` to a configured `www.X`, `non-www` redirects `www.X` to a configured `X`,
     * when the source host is not configured itself.
     *
     * @param  list<string>  $hosts
     * @return array<string, string> Source host => target host.
     */
    public static function wwwRedirects(array $hosts, ?string $mode): array
    {
        $redirects = [];
        foreach ($hosts as $host) {
            $isWww = str_starts_with($host, 'www.');
            $from = match (true) {
                $mode === 'www' && $isWww => substr($host, 4),
                $mode === 'non-www' && ! $isWww => 'www.'.$host,
                default => null,
            };
            if ($from !== null && ! in_array($from, $hosts, true) && self::validHost($from)) {
                $redirects[$from] = $host;
            }
        }

        return $redirects;
    }

    /**
     * One route per host with the fields that every Sentinel accepts, from the route with the
     * shortest path.
     *
     * @param  array<string, array{host: string, workload_id: string, namespace: string, port: int, path: string}>  $routes
     * @return list<array{host: string, workload_id: string, namespace: string, port: int}>
     */
    private static function legacyRoutes(array $routes): array
    {
        return collect($routes)
            ->sortBy(fn (array $route): int => strlen($route['path']))
            ->unique('host')
            ->map(fn (array $route): array => collect($route)->only(['host', 'workload_id', 'namespace', 'port'])->all())
            ->values()
            ->all();
    }

    /** `''` or `/segment...` without a trailing slash, as Sentinel accepts it. */
    public static function validPath(string $path): bool
    {
        return $path === '' || (strlen($path) <= 255 && preg_match('#\A(/[A-Za-z0-9._~!$&()+,;=:@%-]+)+\z#', $path) === 1);
    }

    /**
     * The internal DNS names of the cluster (`<name>.default.coolify.internal`) mapped to workload
     * UUIDs. Sentinel stores them in Corrosion, so a rename applies without a redeploy.
     *
     * @return list<array{name: string, workload_id: string, namespace: string}>
     */
    public static function names(NodeCluster $cluster): array
    {
        $workloads = NodeWorkload::query()
            ->where('team_id', $cluster->team_id)
            ->whereNotNull('internal_dns_name')
            ->whereHas('nodes', fn ($query) => $query->where('nodes.node_cluster_id', $cluster->id))
            ->with('stack')
            ->orderBy('id')
            ->get(['id', 'uuid', 'internal_dns_name', 'node_stack_id', 'stack_service']);
        $names = $workloads
            // Sentinel rejects the whole name table for one invalid or duplicate name.
            ->filter(fn (NodeWorkload $workload): bool => self::validLabel((string) $workload->internal_dns_name))
            ->unique('internal_dns_name')
            ->map(fn (NodeWorkload $workload): array => [
                'name' => $workload->internal_dns_name,
                'workload_id' => $workload->uuid,
                'namespace' => self::NAMESPACE,
            ])
            ->values()
            ->all();
        $names = [...$names, ...self::stackNames($cluster, $workloads)];
        if (count($names) > self::MAX_NAMES) {
            throw new RuntimeException('The cluster has more than '.number_format(self::MAX_NAMES).' internal DNS names. Remove applications to apply the network.');
        }

        return $names;
    }

    /**
     * `<service>.<stack>.coolify.internal` for each service of a stack (decision 0023).
     *
     * @param  Collection<int, NodeWorkload>  $workloads
     * @return list<array{name: string, workload_id: string, namespace: string}>
     */
    private static function stackNames(NodeCluster $cluster, Collection $workloads): array
    {
        $stackWorkloads = $workloads->filter(fn (NodeWorkload $workload): bool => $workload->stack !== null
            && $workload->stack->node_cluster_id === $cluster->id
            && filled($workload->stack_service));
        if ($stackWorkloads->isEmpty()) {
            return [];
        }

        return $stackWorkloads
            ->map(fn (NodeWorkload $workload): array => [
                'name' => strtolower((string) $workload->stack_service),
                'workload_id' => $workload->uuid,
                'namespace' => $workload->stack->dns_label,
            ])
            ->filter(fn (array $name): bool => self::validLabel($name['namespace'])
                && ! in_array($name['namespace'], NodeStack::RESERVED_LABELS, true)
                && self::validLabel($name['name']))
            ->unique(fn (array $name): string => $name['namespace'].'|'.$name['name'])
            ->values()
            ->all();
    }

    /** A lowercase RFC 1123 hostname with at least two labels, as Sentinel accepts it. */
    public static function validHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || ! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        $labels = explode('.', $host);
        if (ctype_digit(end($labels))) {
            return false;
        }

        return collect($labels)->every(fn (string $label): bool => preg_match('/\A(?!-)[a-z0-9-]{1,63}(?<!-)\z/', $label) === 1);
    }

    private static function validLabel(string $value): bool
    {
        return preg_match('/\A(?!-)[a-zA-Z0-9-]{1,63}(?<!-)\z/', $value) === 1;
    }

    /**
     * Workloads with domains that run on a Node of the cluster. Their `nodes` relation
     * only contains the Nodes of this cluster.
     *
     * @return Collection<int, NodeWorkload>
     */
    public static function routedWorkloads(NodeCluster $cluster): Collection
    {
        return NodeWorkload::query()
            ->where('team_id', $cluster->team_id)
            ->whereNotNull('domain_routes')
            ->whereHas('nodes', fn ($query) => $query->where('nodes.node_cluster_id', $cluster->id))
            ->with(['nodes' => fn ($query) => $query->where('nodes.node_cluster_id', $cluster->id)])
            ->orderBy('id')
            ->get()
            ->filter(fn (NodeWorkload $workload): bool => $workload->hasIngressRoutes())
            ->values();
    }
}
