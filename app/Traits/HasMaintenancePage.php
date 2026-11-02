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
