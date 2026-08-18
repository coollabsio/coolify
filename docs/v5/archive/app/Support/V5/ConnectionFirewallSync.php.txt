<?php

namespace App\Support\V5;

use App\Exceptions\V5\UnsupportedCooldVerb;
use App\Models\V5\Application as V5Application;
use App\Models\V5\ResourceConnection;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\FluxClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Single source of truth for deriving node firewall rules from a resource
 * connection's DB rules and converging them through Flux. Reusable from
 * controllers, jobs, and events alike; deterministic rule ids keep repeated
 * syncs and compensating rollbacks idempotent.
 */
class ConnectionFirewallSync
{
    /**
     * @return Collection<int, array{id: string, hostId: string, rule: array{id: string, namespace: string, src: string, dst: string, proto: string, port: int}}>
     */
    public function rulesFor(ResourceConnection $connection): Collection
    {
        $applicationIds = $connection->rules
            ->flatMap(fn ($rule) => [$rule->source_resource_id, $rule->target_resource_id])
            ->unique()
            ->values();

        $applications = V5Application::query()
            ->whereIn('id', $applicationIds)
            ->with('server')
            ->get()
            ->keyBy('id');

        return $connection->rules
            ->flatMap(function ($rule) use ($applications, $connection): Collection {
                $source = $applications->get($rule->source_resource_id);
                $target = $applications->get($rule->target_resource_id);

                if (! $source instanceof V5Application || ! $target instanceof V5Application) {
                    return collect();
                }

                $missingHost = collect([$source, $target])
                    ->first(function (V5Application $application): bool {
                        $hostId = $application->server?->fluxHostId();

                        return ! is_string($hostId) || $hostId === '';
                    });

                if ($missingHost instanceof V5Application) {
                    throw new \RuntimeException("Application {$missingHost->name} has no reachable server host id, so its firewall rules cannot be synced.");
                }

                $hostIds = collect([$source->server, $target->server])
                    ->map(fn (V5Server $server) => $server->fluxHostId())
                    ->unique()
                    ->values();

                $firewallRule = [
                    'id' => $this->ruleId($connection, $rule),
                    'namespace' => $target->mesh_namespace ?: 'default',
                    'src' => $source->container_name,
                    'dst' => $target->container_name,
                    'proto' => $rule->protocol ?: 'tcp',
                    'port' => (int) $rule->port,
                ];

                return $hostIds->map(fn (string $hostId): array => [
                    'id' => $firewallRule['id'],
                    'hostId' => $hostId,
                    'rule' => $firewallRule,
                ]);
            })
            ->values();
    }

    /**
     * @param  Collection<int, array{id: string, hostId: string, rule: array{id: string, namespace: string, src: string, dst: string, proto: string, port: int}}>  $oldRules
     * @param  Collection<int, array{id: string, hostId: string, rule: array{id: string, namespace: string, src: string, dst: string, proto: string, port: int}}>  $newRules
     */
    public function sync(FluxClient $fluxClient, Collection $oldRules, Collection $newRules): void
    {
        $newRuleKeys = $newRules->map(fn (array $rule): string => $this->syncKey($rule))->all();
        $oldRuleKeys = $oldRules->map(fn (array $rule): string => $this->syncKey($rule))->all();

        $oldRules
            ->reject(fn (array $oldRule): bool => in_array($this->syncKey($oldRule), $newRuleKeys, true))
            ->each(fn (array $oldRule): ?string => $this->revokeRuleIfPresent($fluxClient, $oldRule['hostId'], $oldRule['id']));

        $newRules
            ->reject(fn (array $newRule): bool => in_array($this->syncKey($newRule), $oldRuleKeys, true))
            ->each(function (array $newRule) use ($fluxClient): void {
                try {
                    $fluxClient->applyFirewallRule($newRule['hostId'], $newRule['rule']);
                } catch (UnsupportedCooldVerb $exception) {
                    Log::warning('V5 resource connection firewall rule skipped: coold verb unsupported', [
                        'host_id' => $newRule['hostId'],
                        'rule_id' => $newRule['id'],
                        'verb' => $exception->verb,
                        'message' => $exception->getMessage(),
                    ]);
                }
            });
    }

    public function revokeRuleIfPresent(FluxClient $fluxClient, string $hostId, string $ruleId): ?string
    {
        try {
            return $fluxClient->revokeFirewallRule($hostId, $ruleId);
        } catch (UnsupportedCooldVerb $exception) {
            Log::warning('V5 resource connection firewall revoke skipped: coold verb unsupported', [
                'host_id' => $hostId,
                'rule_id' => $ruleId,
                'verb' => $exception->verb,
                'message' => $exception->getMessage(),
            ]);

            return null;
        } catch (\RuntimeException $exception) {
            if (str_contains(Str::lower($exception->getMessage()), 'not found')) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Deterministic node-side rule id derived only from the connection id and
     * the rule's stable attributes — never from the rule row's primary key —
     * so rewritten or restored DB rows resolve to the same firewall rule ids
     * and compensating re-syncs stay idempotent.
     */
    public function ruleId(ResourceConnection $connection, mixed $rule): string
    {
        return implode(':', [
            'v5-resource-connection',
            $connection->id,
            $rule->source_resource_id,
            $rule->target_resource_id,
            $rule->protocol ?: 'tcp',
            (int) $rule->port,
        ]);
    }

    /**
     * @param  array{id: string, hostId: string, rule: array{id: string, namespace: string, src: string, dst: string, proto: string, port: int}}  $rule
     */
    private function syncKey(array $rule): string
    {
        return $rule['hostId'].'|'.$rule['id'];
    }
}
