<?php

namespace App\Livewire\Server\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;
use Symfony\Component\Yaml\Yaml;

class Gateway extends Component
{
    use AuthorizesRequests;

    public ?Server $server = null;

    public $parameters = [];

    public Collection $routes;

    public bool $loading = false;

    public ?bool $dnsChallengeMissing = null;

    protected $listeners = ['gatewayRoutesSaved' => 'loadRoutes'];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->first();
            if (is_null($this->server)) {
                return redirect()->route('server.index');
            }
            if ($this->server->proxyType() !== ProxyTypes::TRAEFIK->value) {
                return redirect()->route('server.proxy', ['server_uuid' => $this->server->uuid]);
            }
            $this->routes = collect();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function initLoadRoutes()
    {
        $this->loadRoutes();
    }

    public function loadRoutes()
    {
        try {
            $this->loading = true;
            $this->checkDnsChallenge();
            $routes = [];
            foreach (self::listRouteFiles($this->server) as $file) {
                $config = self::readRouteFile($this->server, $file);
                $routes = array_merge($routes, self::parseRoutes($config));
            }
            $this->routes = collect($routes)->sortBy('name')->values();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->loading = false;
        }
    }

    private function checkDnsChallenge(): void
    {
        $file = escapeshellarg($this->server->proxyPath().'/docker-compose.yml');
        $contents = instant_remote_process(
            ["test -f {$file} && cat {$file} || echo ''"],
            $this->server,
            throwError: false,
        );
        $this->dnsChallengeMissing = ! str_contains(strtolower($contents ?? ''), 'dnschallenge=true');
    }

    public function deleteRoute(string $routerName, string $password = '')
    {
        try {
            $this->authorize('update', $this->server);
            self::deleteRouteFile($this->server, $routerName);
            $this->loadRoutes();
            $this->dispatch('success', 'Gateway route deleted.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public static function gatewayDirPath(Server $server): string
    {
        return $server->proxyPath().'/dynamic';
    }

    public static function routeFilePath(Server $server, string $routerName): string
    {
        self::assertValidRouterName($routerName);

        return self::gatewayDirPath($server)."/{$routerName}.yaml";
    }

    private static function assertValidRouterName(string $routerName): void
    {
        if (! preg_match('/^gateway-[a-z0-9-]+$/', $routerName)) {
            throw new \InvalidArgumentException('Invalid gateway route name.');
        }
    }

    public static function listRouteFiles(Server $server): array
    {
        $dir = escapeshellarg(self::gatewayDirPath($server));
        $output = instant_remote_process(
            ["ls -1 {$dir}/gateway-*.yaml 2>/dev/null || true"],
            $server,
            throwError: false,
        );

        return collect(preg_split('/\r?\n/', $output ?? ''))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->values()
            ->all();
    }

    public static function readRouteFile(Server $server, string $file): array
    {
        $escapedFile = escapeshellarg($file);
        $contents = instant_remote_process(
            ["test -f {$escapedFile} && cat {$escapedFile} || echo ''"],
            $server,
            throwError: false,
        );
        if (empty(trim($contents ?? ''))) {
            return [];
        }
        $parsed = Yaml::parse($contents);

        return is_array($parsed) ? $parsed : [];
    }

    public static function readRouteConfig(Server $server, string $routerName): array
    {
        return self::readRouteFile($server, self::routeFilePath($server, $routerName));
    }

    public static function writeRouteFile(Server $server, string $routerName, array $config): void
    {
        $file = self::routeFilePath($server, $routerName);
        $escapedFile = escapeshellarg($file);
        $dir = self::gatewayDirPath($server);
        $yaml = Yaml::dump($config, 10, 2);
        $base64 = base64_encode($yaml);
        instant_remote_process([
            "mkdir -p {$dir}",
            "echo '{$base64}' | base64 -d | tee {$escapedFile} > /dev/null",
        ], $server);
    }

    public static function deleteRouteFile(Server $server, string $routerName): void
    {
        $file = self::routeFilePath($server, $routerName);
        $escapedFile = escapeshellarg($file);
        instant_remote_process(["rm -f {$escapedFile}"], $server);
    }

    public static function parseRoutes(array $config): array
    {
        $routers = $config['http']['routers'] ?? [];
        $services = $config['http']['services'] ?? [];
        $routes = [];

        foreach ($routers as $name => $router) {
            // Skip the auto-generated HTTP redirect router; we surface it via the parent route.
            if (str_ends_with($name, '-http') && isset($routers[substr($name, 0, -5)])) {
                continue;
            }

            $rule = $router['rule'] ?? '';
            $domain = '';
            $pathPrefix = '/';

            if (preg_match('/Host\(`([^`]+)`\)/', $rule, $m)) {
                $domain = $m[1];
            } elseif (preg_match('/HostRegexp\(`([^`]+)`\)/', $rule, $m)) {
                $pattern = $m[1];
                $prefix = '^[a-zA-Z0-9-]+\\.';
                if (str_starts_with($pattern, $prefix) && str_ends_with($pattern, '$')) {
                    $base = substr($pattern, strlen($prefix), -1);
                    $domain = '*.'.str_replace('\\.', '.', $base);
                }
            }
            if (preg_match('/PathPrefix\(`([^`]+)`\)/', $rule, $m)) {
                $pathPrefix = $m[1];
            }

            $serviceName = $router['service'] ?? null;
            $service = $serviceName ? ($services[$serviceName] ?? null) : null;
            $targetUrl = $service['loadBalancer']['servers'][0]['url'] ?? '';
            $passHostHeader = (bool) ($service['loadBalancer']['passHostHeader'] ?? false);

            $routerMiddlewares = $router['middlewares'] ?? [];
            $stripPrefix = false;
            foreach ($routerMiddlewares as $mw) {
                if (str_ends_with($mw, '-stripprefix')) {
                    $stripPrefix = true;
                }
            }

            $routes[] = [
                'router_name' => $name,
                'name' => preg_replace('/^gateway-/', '', $name),
                'domain' => $domain,
                'path_prefix' => $pathPrefix,
                'target_url' => $targetUrl,
                'entrypoints' => $router['entryPoints'] ?? [],
                'tls_enabled' => isset($router['tls']),
                'tls_cert_resolver' => $router['tls']['certResolver'] ?? '',
                'pass_host_header' => $passHostHeader,
                'https_redirect' => isset($routers["{$name}-http"]),
                'strip_prefix' => $stripPrefix,
            ];
        }

        return $routes;
    }

    public function render()
    {
        return view('livewire.server.proxy.gateway');
    }
}
