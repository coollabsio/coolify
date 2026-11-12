<?php

namespace App\Actions\Node;

use App\Models\NodeCluster;
use App\Models\NodeWorkload;
use App\Models\User;
use App\Rules\ValidHostname;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Saves the public HTTP domains of a cluster workload and its redirect settings. Routing intent
 * lives on the workload, so a change reaches the ingress Nodes without a redeploy.
 */
class UpdateNodeWorkloadDomains
{
    use AsAction;

    public const MAX_DOMAINS = 20;

    public const WWW_REDIRECTS = ['both', 'www', 'non-www'];

    /**
     * @param  list<array{scheme?: string, host?: string, port?: int|string|null, path?: string|null, noindex?: bool}>  $routes
     * @param  array{www_redirect?: string, force_https?: bool, permanent_redirect?: bool}  $redirects  Changed settings only.
     *
     * @throws ValidationException with `routes` and redirect setting keys.
     */
    public function handle(NodeWorkload $workload, array $routes, User $user, array $redirects = []): NodeWorkload
    {
        Gate::forUser($user)->authorize('update', $workload);
        // A database has no public HTTP domain (decision 0010); public access uses port publishing.
        if ($workload->isDatabase() && $routes !== []) {
            throw ValidationException::withMessages(['routes' => 'A database cannot have a domain.']);
        }
        $routes = array_map(self::normalizeRoute(...), array_values($routes));

        $validator = Validator::make(['routes' => $routes, ...$redirects], [
            'routes' => ['array', 'max:'.self::MAX_DOMAINS],
            'routes.*.scheme' => ['required', 'in:http,https'],
            'routes.*.host' => ['required', 'string', $this->domainRule()],
            'routes.*.port' => ['required', 'integer', 'between:1,65535'],
            'routes.*.path' => ['present', 'string', 'max:255', $this->pathRule()],
            'www_redirect' => ['sometimes', 'in:'.implode(',', self::WWW_REDIRECTS)],
            'force_https' => ['sometimes', 'boolean'],
            'permanent_redirect' => ['sometimes', 'boolean'],
        ], [
            'routes.max' => 'Use at most '.self::MAX_DOMAINS.' domains.',
            'routes.*.host.required' => 'Enter a domain.',
            'routes.*.port.required' => 'Set the container port that receives HTTP traffic.',
            'routes.*.port.integer' => 'The port must be a number between 1 and 65535.',
            'routes.*.port.between' => 'The port must be a number between 1 and 65535.',
        ]);
        $validator->after(function ($validator) use ($routes): void {
            $duplicate = collect($routes)->duplicates(fn (array $route): string => $route['host'].$route['path'])->keys()->first();
            if ($duplicate !== null) {
                $validator->errors()->add('routes', self::url($routes[$duplicate]).' is listed more than once.');
            }
            $mixed = collect($routes)->groupBy('host')->first(fn ($hostRoutes) => $hostRoutes->pluck('scheme')->unique()->count() > 1);
            if ($mixed !== null) {
                $validator->errors()->add('routes', "All paths of {$mixed->first()['host']} must use the same protocol.");
            }
        });
        $validator->validate();
        $redirects = collect($redirects)->only(['www_redirect', 'force_https', 'permanent_redirect'])
            ->map(fn ($value, string $key) => $key === 'www_redirect' ? $value : (bool) $value)
            ->all();
        $domains = collect($routes)->pluck('host')->unique()->values()->all();

        // One writer at a time, so two applications cannot claim the same domain concurrently.
        $changed = Cache::lock('node-workload-domains', 30)->block(10, fn (): bool => DB::transaction(function () use ($workload, $routes, $domains, $redirects, $user): bool {
            $workload = NodeWorkload::query()->lockForUpdate()->findOrFail($workload->id);
            if ($domains !== []) {
                // Each cluster serves only its own team's routes, so a domain is unique per team.
                $taken = NodeWorkload::query()
                    ->where('team_id', $workload->team_id)
                    ->whereKeyNot($workload->id)
                    ->whereNotNull('domains')
                    ->where(function ($query) use ($domains): void {
                        foreach ($domains as $domain) {
                            $query->orWhereJsonContains('domains', $domain);
                        }
                    })
                    ->pluck('domains')
                    ->flatten()
                    ->intersect($domains)
                    ->first();
                if ($taken !== null) {
                    throw ValidationException::withMessages(['routes' => "{$taken} is already used by another application."]);
                }
                // v4 applications and services of the team route through another proxy, so the same host cannot serve both.
                $legacyConflict = legacyResourceDomainConflict($domains, $workload->team_id, $user);
                if ($legacyConflict !== null) {
                    throw ValidationException::withMessages(['routes' => $legacyConflict]);
                }
            }

            $wasRouted = $workload->hasIngressRoutes();
            $workload->fill([
                'domains' => $domains === [] ? null : $domains,
                'domain_routes' => $routes === [] ? null : $routes,
                ...$redirects,
            ]);
            $dirty = $workload->isDirty();
            $workload->save();

            return $dirty && ($wasRouted || $workload->hasIngressRoutes());
        }));

        $workload->refresh();
        if ($changed) {
            NodeCluster::query()
                ->whereKey($workload->clusterIds())
                ->get()
                ->each(fn (NodeCluster $cluster) => QueueNodeClusterNetworkRevision::run($cluster, $user));
        }

        return $workload;
    }

    /** @return array{scheme: string, host: string, port: int|string|null, path: string, noindex: bool} */
    public static function normalizeRoute(array $route): array
    {
        $port = trim((string) ($route['port'] ?? ''));
        $path = trim((string) ($route['path'] ?? ''));
        if ($path !== '' && ! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return [
            'scheme' => strtolower(trim((string) ($route['scheme'] ?? 'https'))),
            'host' => strtolower(trim((string) ($route['host'] ?? ''))),
            'port' => ctype_digit($port) ? (int) $port : ($port === '' ? null : $port),
            'path' => rtrim($path, '/'),
            'noindex' => (bool) ($route['noindex'] ?? false),
        ];
    }

    /** @param  array{scheme: string, host: string, path: string}  $route */
    public static function url(array $route): string
    {
        return $route['scheme'].'://'.$route['host'].$route['path'];
    }

    private function pathRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $path = (string) $value;
            if ($path === '') {
                return;
            }
            if (str_contains($path, '?') || str_contains($path, '#')) {
                $fail("Enter the path {$path} without a query or fragment.");
            } elseif (! BuildNodeClusterIngressRoutes::validPath($path)) {
                $fail("{$path} is not a valid path, like /api.");
            }
        };
    }

    private function domainRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $domain = (string) $value;
            $message = match (true) {
                str_contains($domain, '://') => "Enter {$domain} without http:// or https://.",
                str_contains($domain, '*') => "{$domain} is a wildcard domain. Wildcard domains are not supported yet.",
                filter_var(trim($domain, '[]'), FILTER_VALIDATE_IP) !== false => "{$domain} is an IP address. Use a domain name.",
                str_contains($domain, '/') => "Enter {$domain} without a path.",
                str_contains($domain, ':') => "Enter {$domain} without a port. Set the port in its own field.",
                ! str_contains($domain, '.') => "{$domain} is not a fully qualified domain, like app.example.com.",
                ctype_digit((string) str($domain)->afterLast('.')) => "{$domain} is not a valid domain.",
                default => null,
            };
            if ($message !== null) {
                $fail($message);

                return;
            }
            (new ValidHostname)->validate($attribute, $domain, function (string $error) use ($domain, $fail): void {
                $fail(str_replace(':attribute', $domain, $error));
            });
        };
    }
}
