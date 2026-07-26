<?php

namespace App\Http\Controllers\V5;

use App\Http\Controllers\Controller;
use App\Http\Controllers\V5\Concerns\ResolvesCurrentTeam;
use App\Http\Controllers\V5\Concerns\ResolvesProjectSelection;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\V5\Application as V5Application;
use App\Models\V5\ResourceConnection;
use App\Services\Flux\FluxClient;
use App\Support\V5\ConnectionFirewallSync;
use App\Support\V5\ResourceConnectionSerializer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ResourceConnectionController extends Controller
{
    use ResolvesCurrentTeam;
    use ResolvesProjectSelection;

    public function __construct(
        private readonly ConnectionFirewallSync $firewallSync,
        private readonly ResourceConnectionSerializer $connectionSerializer,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('create', [ResourceConnection::class, $currentTeam]);
        $projects = $this->projects($currentTeam);
        [$selectedProject, $selectedEnvironment] = $this->selectedProjectAndEnvironment($request, $projects);

        if ($selectedProject === null || $selectedEnvironment === null) {
            return response()->json([
                'message' => 'Select a project and environment before connecting resources.',
            ], 422);
        }

        $project = $this->projectQuery($currentTeam)
            ->where('uuid', $selectedProject['uuid'])
            ->first();

        if (! $project instanceof Project) {
            abort(403);
        }

        $environment = $this->selectedEnvironment($project, $selectedEnvironment['uuid']);

        if (! $environment instanceof Environment) {
            abort(403);
        }

        $validated = $request->validate([
            'resource_one' => ['required', 'array'],
            'resource_one.type' => ['required', 'string', Rule::in(['application'])],
            'resource_one.uuid' => ['required', 'string', 'max:255'],
            'resource_two' => ['required', 'array'],
            'resource_two.type' => ['required', 'string', Rule::in(['application'])],
            'resource_two.uuid' => ['required', 'string', 'max:255'],
        ]);

        $resourceOne = $this->resolveConnectableResource($currentTeam, $project, $environment, $validated['resource_one']);
        $resourceTwo = $this->resolveConnectableResource($currentTeam, $project, $environment, $validated['resource_two']);

        if ($this->resourceIdentity($resourceOne) === $this->resourceIdentity($resourceTwo)) {
            return response()->json([
                'message' => 'A resource cannot connect to itself.',
            ], 422);
        }

        $connection = ResourceConnection::query()->firstOrCreate(
            [
                'team_id' => $currentTeam->id,
                'resource_pair_key' => $this->resourcePairKey($resourceOne, $resourceTwo),
            ],
            [
                'project_id' => $project->id,
                'environment_id' => $environment->id,
                'resource_one_type' => $resourceOne->getMorphClass(),
                'resource_one_id' => $resourceOne->getKey(),
                'resource_two_type' => $resourceTwo->getMorphClass(),
                'resource_two_id' => $resourceTwo->getKey(),
                'created_by_user_id' => $request->user()->id,
            ],
        );

        return response()->json([
            'connection' => $this->connectionSerializer->serialize($connection->load('rules')),
        ], $connection->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Update the connection's rules, then converge the node firewalls.
     *
     * Ordering & failure semantics:
     * 1. Snapshot the current DB rules and their firewall representation; abort
     *    with 502 before mutating anything when the snapshot cannot be built.
     * 2. Commit the requested rules in a DB transaction — the DB always holds
     *    the desired state.
     * 3. Converge the node firewalls through Flux. Nodes whose coold lacks the
     *    firewall verbs (UnsupportedCooldVerb) are tolerated: the committed
     *    rules are kept and the request succeeds.
     * 4. On a real Flux failure the previous rules are restored in a second DB
     *    transaction, the node firewalls are rolled back to the restored rules
     *    best-effort (warning-logged when that also fails — deterministic rule
     *    ids keep a later re-sync idempotent), and the original error surfaces
     *    to the caller as a 502 {message, detail} response.
     */
    public function update(Request $request, ResourceConnection $connection, FluxClient $fluxClient): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('update', [$connection, $currentTeam]);

        $validated = $request->validate([
            'ports_by_direction' => ['present', 'array'],
            'ports_by_direction.*' => ['array'],
            'ports_by_direction.*.*' => ['integer', 'min:1', 'max:65535', 'distinct'],
        ]);

        $connection->load('rules');
        $oldRulePayloads = $connection->rules
            ->map(fn ($rule): array => [
                'source_resource_type' => $rule->source_resource_type,
                'source_resource_id' => $rule->source_resource_id,
                'target_resource_type' => $rule->target_resource_type,
                'target_resource_id' => $rule->target_resource_id,
                'protocol' => $rule->protocol,
                'port' => $rule->port,
            ])
            ->all();

        try {
            $oldFirewallRules = $this->firewallSync->rulesFor($connection);
        } catch (\RuntimeException $exception) {
            report($exception);
            Log::warning('V5 resource connection firewall snapshot failed', [
                'connection_id' => $connection->id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Could not sync firewall rules through Flux.',
                'detail' => 'The connection was left unchanged. Check the server diagnostics and try again.',
            ], 502);
        }

        DB::transaction(function () use ($connection, $validated): void {
            $connection->rules()->delete();
            $resourcesByUuid = $this->connectionSerializer->applicationsByUuid($connection);

            foreach ($validated['ports_by_direction'] as $direction => $ports) {
                [$sourceResourceUuid, $targetResourceUuid] = array_pad(explode('->', (string) $direction, 2), 2, null);
                $sourceResource = is_string($sourceResourceUuid) ? $resourcesByUuid->get($sourceResourceUuid) : null;
                $targetResource = is_string($targetResourceUuid) ? $resourcesByUuid->get($targetResourceUuid) : null;

                if (! $sourceResource instanceof V5Application || ! $targetResource instanceof V5Application) {
                    continue;
                }

                foreach (array_unique($ports) as $port) {
                    $connection->rules()->create([
                        'source_resource_type' => $this->resourceTypeForConnectionUuid($connection, $sourceResource->uuid),
                        'source_resource_id' => $sourceResource->id,
                        'target_resource_type' => $this->resourceTypeForConnectionUuid($connection, $targetResource->uuid),
                        'target_resource_id' => $targetResource->id,
                        'protocol' => 'tcp',
                        'port' => (int) $port,
                    ]);
                }
            }
        });

        $connection->refresh()->load('rules');

        $newFirewallRules = null;

        try {
            $newFirewallRules = $this->firewallSync->rulesFor($connection);
            $this->firewallSync->sync($fluxClient, $oldFirewallRules, $newFirewallRules);
        } catch (\RuntimeException $exception) {
            $this->restoreConnectionRules($connection, $oldRulePayloads);
            $this->rollBackFirewallRules($fluxClient, $connection, $newFirewallRules, $oldFirewallRules);
            report($exception);
            Log::warning('V5 resource connection firewall sync failed', [
                'connection_id' => $connection->id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Could not sync firewall rules through Flux.',
                'detail' => 'The previous rules were restored. Check the server diagnostics and try again.',
            ], 502);
        }

        return response()->json([
            'connection' => $this->connectionSerializer->serialize($connection),
        ]);
    }

    /**
     * Delete the connection using revoke-first ordering.
     *
     * The node firewall rules are revoked before any DB rows are removed. When
     * a revoke fails with a real error the delete is aborted with a 502
     * {message, detail} response so the DB never loses track of rules that may
     * still be open on a reachable node; UnsupportedCooldVerb and
     * already-missing rules are tolerated. When the firewall snapshot cannot
     * be built (an endpoint lost its server host id) the node cannot be
     * addressed at all, so the failure is reported and the delete proceeds.
     * Deterministic rule ids make a retried delete revoke the same node-side
     * rules idempotently.
     */
    public function destroy(Request $request, ResourceConnection $connection, FluxClient $fluxClient): Response|JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('delete', [$connection, $currentTeam]);

        try {
            $oldFirewallRules = $this->firewallSync->rulesFor($connection->load('rules'));
        } catch (\RuntimeException $exception) {
            report($exception);
            $oldFirewallRules = collect();
        }

        try {
            $this->firewallSync->sync($fluxClient, $oldFirewallRules, collect());
        } catch (\RuntimeException $exception) {
            report($exception);
            Log::warning('V5 resource connection firewall revoke failed', [
                'connection_id' => $connection->id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Could not sync firewall rules through Flux.',
                'detail' => 'The connection was not deleted. Check the server diagnostics and try again.',
            ], 502);
        }

        $connection->delete();

        return response()->noContent();
    }

    /**
     * @param  array{type: string, uuid: string}  $resource
     */
    private function resolveConnectableResource(Team $team, Project $project, Environment $environment, array $resource): Model
    {
        return match ($resource['type']) {
            'application' => V5Application::query()
                ->where('team_id', $team->id)
                ->where('project_id', $project->id)
                ->where('environment_id', $environment->id)
                ->where('uuid', $resource['uuid'])
                ->firstOrFail(),
        };
    }

    private function resourcePairKey(Model $resourceOne, Model $resourceTwo): string
    {
        return collect([
            $this->resourceIdentity($resourceOne),
            $this->resourceIdentity($resourceTwo),
        ])->sort()->implode('|');
    }

    private function resourceIdentity(Model $resource): string
    {
        return $resource->getMorphClass().':'.$resource->getKey();
    }

    private function resourceTypeForConnectionUuid(ResourceConnection $connection, string $resourceUuid): string
    {
        $resourcesByUuid = $this->connectionSerializer->applicationsByUuid($connection);
        $resource = $resourcesByUuid->get($resourceUuid);

        return $resource instanceof V5Application && (int) $connection->resource_one_id === $resource->id
            ? $connection->resource_one_type
            : $connection->resource_two_type;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rulePayloads
     */
    private function restoreConnectionRules(ResourceConnection $connection, array $rulePayloads): void
    {
        DB::transaction(function () use ($connection, $rulePayloads): void {
            $connection->rules()->delete();

            foreach ($rulePayloads as $rulePayload) {
                $connection->rules()->create($rulePayload);
            }
        });
    }

    /**
     * Best-effort roll back of a partially converged node firewall to the
     * restored rules after a failed forward sync. Skipped when the forward
     * sync never started (the node was not touched). Failures are only logged
     * because the DB already holds the restored, authoritative rules and the
     * deterministic rule ids keep a later re-sync idempotent.
     *
     * @param  Collection<int, array{id: string, hostId: string, rule: array{id: string, namespace: string, src: string, dst: string, proto: string, port: int}}>|null  $attemptedFirewallRules
     * @param  Collection<int, array{id: string, hostId: string, rule: array{id: string, namespace: string, src: string, dst: string, proto: string, port: int}}>  $restoredFirewallRules
     */
    private function rollBackFirewallRules(FluxClient $fluxClient, ResourceConnection $connection, ?Collection $attemptedFirewallRules, Collection $restoredFirewallRules): void
    {
        if (! $attemptedFirewallRules instanceof Collection) {
            return;
        }

        try {
            $this->firewallSync->sync($fluxClient, $attemptedFirewallRules, $restoredFirewallRules);
        } catch (\RuntimeException $exception) {
            Log::warning('V5 resource connection firewall rollback failed; node firewall may diverge from the restored rules until the next sync', [
                'connection_id' => $connection->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
