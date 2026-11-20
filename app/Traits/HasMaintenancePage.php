<?php

namespace App\Traits;

use App\Models\Server;
use Illuminate\Support\Collection;
use Spatie\Url\Url;

/**
 * Maintenance mode for a resource with domains: the proxy shows a maintenance page with
 * status 503 instead of the resource. The resource containers keep running.
 *
 * @property bool $is_maintenance_enabled
 * @property string|null $maintenance_page
 */
trait HasMaintenancePage
{
    /**
     * @return Collection<int, array{url: string, force_https: bool}>
     */
    abstract public function maintenanceDomains(): Collection;

    /**
     * @return Collection<int, Server>
     */
    abstract public function maintenanceServers(): Collection;

    /**
     * Proxy routes for the maintenance page. Domains go into the Traefik and Caddy configuration
     * as plain text, so a domain with an unexpected host or path is skipped.
     *
     * @return Collection<int, array{scheme: string, host: string, path: string, force_https: bool}>
     */
    public function maintenanceRoutes(): Collection
    {
        return $this->maintenanceDomains()
            ->map(function (array $domain): ?array {
                try {
                    $url = Url::fromString(trim($domain['url']));
                } catch (\Throwable) {
                    return null;
                }
                // Host and scheme stay as the proxy labels use them, so the Caddy site address is the same.
                $scheme = $url->getScheme();
                $host = $url->getHost();
                $path = $url->getPath() ?: '/';

                if (! in_array($scheme, ['http', 'https'], true)
                    || ! preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $host)
                    || ! preg_match('#^/[A-Za-z0-9/._~%-]*$#', $path)) {
                    return null;
                }

                return ['scheme' => $scheme, 'host' => $host, 'path' => $path, 'force_https' => $domain['force_https']];
            })
            ->filter()
            ->unique(fn (array $route) => "{$route['scheme']}://{$route['host']}{$route['path']}")
            ->values();
    }

    /**
     * Container labels that the proxy routes the resource with, when the user edits them.
     * Null when the labels come from the domains, so the domain routes apply.
     *
     * @return Collection<int, string>|null
     */
    public function maintenanceProxyLabels(): ?Collection
    {
        return null;
    }

    /**
     * Traefik routers that show the maintenance page. With custom labels, each label router
     * gets a maintenance router with the same rule, so wildcard and custom rules also match.
     *
     * @return Collection<int, array{name: string, rule: string, entryPoints: array<int, string>|null, tls: array<string, mixed>|null, priority: int}>
     */
    public function maintenanceTraefikRouters(): Collection
    {
        $labels = $this->maintenanceProxyLabels();
        if ($labels !== null) {
            return $this->maintenanceTraefikRoutersFromLabels($labels);
        }

        return $this->maintenanceRoutes()->flatMap(function (array $route, int $index): array {
            $rule = "Host(`{$route['host']}`) && PathPrefix(`{$route['path']}`)";
            $router = ['name' => "{$index}-http", 'rule' => $rule, 'entryPoints' => ['http'], 'tls' => null, 'priority' => strlen($rule) + 1];
            if ($route['scheme'] !== 'https') {
                return [$router];
            }

            return [$router, array_merge($router, ['name' => "{$index}-https", 'entryPoints' => ['https'], 'tls' => ['certResolver' => 'letsencrypt']])];
        })->values();
    }

    /**
     * Caddy site addresses and path matchers that show the maintenance page. A site address
     * must be the same as in the labels, so caddy-docker-proxy merges both into one site block.
     *
     * @return Collection<int, array{address: string, path: string}>
     */
    public function maintenanceCaddySites(): Collection
    {
        $labels = $this->maintenanceProxyLabels();
        if ($labels !== null) {
            return $this->maintenanceCaddySitesFromLabels($labels);
        }

        return $this->maintenanceRoutes()
            ->map(fn (array $route) => [
                'address' => caddySiteAddress($route['scheme'], $route['host'], $route['force_https']),
                'path' => "{$route['path']}*",
            ])
            ->values();
    }

    /**
     * @param  Collection<int, string>  $labels
     * @return Collection<int, array{name: string, rule: string, entryPoints: array<int, string>|null, tls: array<string, mixed>|null, priority: int}>
     */
    protected function maintenanceTraefikRoutersFromLabels(Collection $labels): Collection
    {
        $values = $this->maintenanceLabelValues($labels);
        if (strtolower($values['traefik.enable'] ?? 'true') === 'false') {
            return collect();
        }

        $routers = [];
        foreach ($values as $key => $value) {
            if (preg_match('/^traefik\.http\.routers\.([A-Za-z0-9_-]+)\.(.+)$/', $key, $matches)) {
                $routers[$matches[1]][$matches[2]] = $value;
            }
        }

        return collect($routers)
            ->filter(fn (array $router) => filled($router['rule'] ?? null))
            ->map(function (array $router, string $name): array {
                $options = array_change_key_case($router);
                $tls = null;
                if (collect($options)->keys()->contains(fn (string $key) => str_starts_with($key, 'tls.'))
                    || strtolower($options['tls'] ?? '') === 'true') {
                    $tls = filled($options['tls.certresolver'] ?? null)
                        ? ['certResolver' => $options['tls.certresolver']]
                        : ['options' => $options['tls.options'] ?? 'default'];
                }
                $entryPoints = filled($options['entrypoints'] ?? null)
                    ? array_values(array_filter(array_map('trim', explode(',', $options['entrypoints']))))
                    : null;
                $priority = is_numeric($options['priority'] ?? null) ? (int) $options['priority'] : strlen($router['rule']);

                return ['name' => $name, 'rule' => $router['rule'], 'entryPoints' => $entryPoints, 'tls' => $tls, 'priority' => $priority + 1];
            })
            ->values();
    }

    /**
     * @param  Collection<int, string>  $labels
     * @return Collection<int, array{address: string, path: string}>
     */
    protected function maintenanceCaddySitesFromLabels(Collection $labels): Collection
    {
        $values = $this->maintenanceLabelValues($labels);

        return collect($values)
            ->filter(fn (string $value, string $key) => preg_match('/^caddy(_\d+)?$/', $key) === 1)
            ->map(function (string $address, string $key) use ($values): ?array {
                $address = trim($address);
                $path = trim($values["{$key}.handle_path"] ?? $values["{$key}.handle"] ?? '') ?: '/*';
                // The values go into the Caddyfile as plain text.
                if (! preg_match('#^[A-Za-z0-9.*:/_\[\], -]+$#', $address) || ! preg_match('#^/[A-Za-z0-9/._~%*-]*$#', $path)) {
                    return null;
                }

                return ['address' => $address, 'path' => $path];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, string>  $labels
     * @return array<string, string>
     */
    private function maintenanceLabelValues(Collection $labels): array
    {
        $values = [];
        foreach ($labels as $label) {
            $label = trim((string) $label);
            if (str_contains($label, '=') && ! str_starts_with($label, '#')) {
                [$key, $value] = explode('=', $label, 2);
                $values[trim($key)] = $value;
            }
        }

        return $values;
    }

    /**
     * The custom maintenance page HTML, or the Coolify default page.
     */
    public function maintenancePageHtml(): string
    {
        return filled($this->maintenance_page) ? $this->maintenance_page : view('proxy.maintenance-page')->render();
    }

    public function maintenancePageFile(): string
    {
        return "maintenance-{$this->uuid}.html";
    }

    /**
     * Applies the maintenance state of this resource on every server that runs it.
     */
    public function syncMaintenancePage(?Collection $servers = null): void
    {
        foreach ($servers ?? $this->maintenanceServers() as $server) {
            if ($server->isFunctional()) {
                $server->setupMaintenancePages();
            }
        }
    }
}
