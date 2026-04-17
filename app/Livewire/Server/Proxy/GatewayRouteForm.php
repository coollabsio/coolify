<?php

namespace App\Livewire\Server\Proxy;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class GatewayRouteForm extends Component
{
    use AuthorizesRequests;

    public $server_id;

    public ?string $routerName = null;

    public ?string $sourceFile = null;

    public Server $server;

    public string $name = '';

    public string $domain = '';

    public string $target_url = '';

    public string $path_prefix = '/';

    public string $entrypoints_input = 'https';

    public string $tls_enabled = '1';

    public string $tls_cert_resolver = 'letsencrypt';

    public string $https_redirect = '0';

    public string $pass_host_header = '1';

    public string $strip_prefix = '0';

    public function mount()
    {
        $this->server = Server::ownedByCurrentTeam()->whereId($this->server_id)->firstOrFail();

        if ($this->routerName) {
            $existing = Gateway::findRoute($this->server, $this->routerName);

            if ($existing) {
                $this->sourceFile = $existing['source_file'];
                $this->name = $existing['name'];
                $this->domain = $existing['domain'];
                $this->target_url = $existing['target_url'];
                $this->path_prefix = $existing['path_prefix'];
                $this->entrypoints_input = implode(',', $existing['entrypoints']);
                $this->tls_enabled = $existing['tls_enabled'] ? '1' : '0';
                $this->tls_cert_resolver = $existing['tls_cert_resolver'];
                $this->https_redirect = $existing['https_redirect'] ? '1' : '0';
                $this->pass_host_header = $existing['pass_host_header'] ? '1' : '0';
                $this->strip_prefix = $existing['strip_prefix'] ? '1' : '0';
            }
        }
    }

    public function save()
    {
        try {
            $this->authorize('update', $this->server);

            $this->validate([
                'name' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9 _\-]+$/'],
                'domain' => ['required', 'string', 'max:255', 'regex:'.Gateway::DOMAIN_REGEX],
                'target_url' => ['required', 'url:http,https', 'max:500'],
                'path_prefix' => ['required', 'string', 'max:255', 'regex:#^/[A-Za-z0-9._\-/]*$#'],
                'entrypoints_input' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_,\s\-]*$/'],
                'tls_cert_resolver' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]*$/'],
            ]);

            $entrypoints = array_values(array_filter(array_map('trim', explode(',', $this->entrypoints_input))));
            if (empty($entrypoints)) {
                throw ValidationException::withMessages([
                    'entrypoints_input' => 'At least one entrypoint is required (e.g. https).',
                ]);
            }

            $slug = str($this->name)->slug()->toString();
            if ($slug === '') {
                throw ValidationException::withMessages([
                    'name' => 'Name must contain at least one letter or number.',
                ]);
            }
            $newRouterName = "gateway-{$slug}";

            if ($newRouterName !== $this->routerName) {
                if (Gateway::findRoute($this->server, $newRouterName) !== null) {
                    throw ValidationException::withMessages([
                        'name' => "A route named '{$this->name}' already exists on this server.",
                    ]);
                }

                if (! $this->sourceFile) {
                    $newPath = Gateway::routeFilePath($this->server, $newRouterName);
                    if (Gateway::fileExistsAt($this->server, $newPath)) {
                        throw ValidationException::withMessages([
                            'name' => "A file named '{$newRouterName}.yaml' already exists on this server.",
                        ]);
                    }
                }
            }

            $delta = $this->buildRouteConfig($newRouterName, $entrypoints);

            if ($this->sourceFile) {
                $config = Gateway::readRouteFile($this->server, $this->sourceFile);
                if ($this->routerName) {
                    $config = Gateway::stripRouter($config, $this->routerName);
                }
                $config = $this->mergeDelta($config, $delta);
                Gateway::writeFile($this->server, $this->sourceFile, $config);
            } else {
                $file = Gateway::routeFilePath($this->server, $newRouterName);
                Gateway::writeFile($this->server, $file, $delta);
            }

            $this->dispatch('gatewayRoutesSaved');
            $this->dispatch('success', 'Gateway route saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function buildRouteConfig(string $routerName, array $entrypoints): array
    {
        $serviceName = $routerName;

        $tlsEnabled = $this->tls_enabled === '1';
        $httpsRedirect = $this->https_redirect === '1';
        $passHostHeader = $this->pass_host_header === '1';
        $stripPrefix = $this->strip_prefix === '1';

        if (str_starts_with($this->domain, '*.')) {
            $base = substr($this->domain, 2);
            $escapedBase = str_replace('.', '\\.', $base);
            $rule = "HostRegexp(`^[a-zA-Z0-9-]+\\.{$escapedBase}$`)";
        } else {
            $rule = "Host(`{$this->domain}`)";
        }
        if ($this->path_prefix && $this->path_prefix !== '/') {
            $rule .= " && PathPrefix(`{$this->path_prefix}`)";
        }

        $routers = [];
        $services = [];
        $middlewares = [];

        $routerMiddlewares = [];

        if ($stripPrefix && $this->path_prefix && $this->path_prefix !== '/') {
            $middlewares["{$routerName}-stripprefix"] = [
                'stripPrefix' => ['prefixes' => [$this->path_prefix]],
            ];
            $routerMiddlewares[] = "{$routerName}-stripprefix";
        }

        $httpsRouter = [
            'rule' => $rule,
            'entryPoints' => $entrypoints,
            'service' => $serviceName,
        ];
        if (! empty($routerMiddlewares)) {
            $httpsRouter['middlewares'] = $routerMiddlewares;
        }
        if ($tlsEnabled) {
            $httpsRouter['tls'] = $this->tls_cert_resolver !== ''
                ? ['certResolver' => $this->tls_cert_resolver]
                : [];
        }
        $routers[$routerName] = $httpsRouter;

        if ($httpsRedirect) {
            $middlewares["{$routerName}-redirect"] = [
                'redirectScheme' => ['scheme' => 'https'],
            ];
            $routers["{$routerName}-http"] = [
                'rule' => $rule,
                'entryPoints' => ['http'],
                'service' => $serviceName,
                'middlewares' => ["{$routerName}-redirect"],
            ];
        }

        $services[$serviceName] = [
            'loadBalancer' => [
                'passHostHeader' => $passHostHeader,
                'servers' => [['url' => $this->target_url]],
            ],
        ];

        $config = ['http' => [
            'routers' => $routers,
            'services' => $services,
        ]];
        if (! empty($middlewares)) {
            $config['http']['middlewares'] = $middlewares;
        }

        return $config;
    }

    private function mergeDelta(array $config, array $delta): array
    {
        $config['http'] = $config['http'] ?? [];

        foreach (['routers', 'services', 'middlewares'] as $section) {
            $existing = $config['http'][$section] ?? [];
            $incoming = $delta['http'][$section] ?? [];
            if (! empty($incoming)) {
                $config['http'][$section] = array_merge($existing, $incoming);
            }
        }

        return $config;
    }

    public function render()
    {
        return view('livewire.server.proxy.gateway-route-form');
    }
}
