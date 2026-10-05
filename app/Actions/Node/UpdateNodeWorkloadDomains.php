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
 * Saves the public HTTP domains and container port of a cluster workload. Routing intent lives on
 * the workload, so a change reaches the ingress Nodes without a redeploy.
 */
class UpdateNodeWorkloadDomains
{
    use AsAction;

    public const MAX_DOMAINS = 20;

    /**
     * @param  string|list<string>  $domains  Comma, space, or newline separated domains.
     *
     * @throws ValidationException with `domains` and `http_port` keys.
     */
    public function handle(NodeWorkload $workload, string|array $domains, mixed $httpPort, User $user): NodeWorkload
    {
        Gate::forUser($user)->authorize('update', $workload);
        $domains = self::normalizeDomains($domains);
        $httpPort = blank($httpPort) ? null : $httpPort;

        $validated = Validator::make(['domains' => $domains, 'http_port' => $httpPort], [
            'domains' => ['array', 'max:'.self::MAX_DOMAINS],
            'domains.*' => ['string', $this->domainRule()],
            'http_port' => [$domains === [] ? 'nullable' : 'required', 'integer', 'between:1,65535'],
        ], [
            'domains.max' => 'Use at most '.self::MAX_DOMAINS.' domains.',
            'http_port.required' => 'Set the container port that receives HTTP traffic.',
        ], ['http_port' => 'port'])->validate();
        $duplicates = collect($domains)->duplicates()->unique()->values();
        if ($duplicates->isNotEmpty()) {
            throw ValidationException::withMessages(['domains' => "{$duplicates->first()} is listed more than once."]);
        }
        $httpPort = $validated['http_port'] === null ? null : (int) $validated['http_port'];

        // One writer at a time, so two applications cannot claim the same domain concurrently.
        $changed = Cache::lock('node-workload-domains', 30)->block(10, fn (): bool => DB::transaction(function () use ($workload, $domains, $httpPort): bool {
            $workload = NodeWorkload::query()->lockForUpdate()->findOrFail($workload->id);
            if ($domains !== []) {
                $taken = NodeWorkload::query()
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
                    throw ValidationException::withMessages(['domains' => "{$taken} is already used by another application."]);
                }
            }

            $wasRouted = $workload->hasIngressRoutes();
            $previous = [$workload->domains ?? [], $workload->http_port];
            $workload->update(['domains' => $domains === [] ? null : $domains, 'http_port' => $httpPort]);

            return $previous !== [$domains, $httpPort] && ($wasRouted || $workload->hasIngressRoutes());
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

    /**
     * @param  string|list<string>  $domains
     * @return list<string>
     */
    public static function normalizeDomains(string|array $domains): array
    {
        $values = is_array($domains) ? $domains : preg_split('/[\s,]+/', $domains);

        return collect($values)
            ->map(fn ($domain): string => strtolower(trim((string) $domain)))
            ->filter(fn (string $domain): bool => $domain !== '')
            ->values()
            ->all();
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
