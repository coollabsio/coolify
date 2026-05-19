<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KubernetesCluster;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DestinationsController extends Controller
{
    public function destinations()
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $destinations = collect()
            ->concat(StandaloneDocker::ownedByCurrentTeamAPI($teamId)->with('server')->get())
            ->concat(SwarmDocker::ownedByCurrentTeamAPI($teamId)->with('server')->get())
            ->concat(KubernetesCluster::ownedByCurrentTeamAPI($teamId)->with('server')->get())
            ->map(fn ($destination) => $this->payload($destination))
            ->values();

        return response()->json($destinations);
    }

    public function destination_by_uuid(string $uuid)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $destination = $this->findDestination($teamId, $uuid);
        if ($destination === null) {
            return response()->json(['message' => 'Destination not found.'], 404);
        }

        return response()->json($this->payload($destination));
    }

    public function create_kubernetes(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validated = Validator::make($request->all(), $this->rules(required: true))->validate();
        $server = $this->server($teamId, $validated['server_uuid']);
        if ($server === null) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $destination = KubernetesCluster::create($this->attributes($validated, $server));

        return response()->json($this->payload($destination->load('server')), 201);
    }

    public function update_kubernetes(Request $request, string $uuid)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $destination = KubernetesCluster::ownedByCurrentTeamAPI($teamId)->whereUuid($uuid)->first();
        if ($destination === null) {
            return response()->json(['message' => 'Kubernetes destination not found.'], 404);
        }

        $validated = Validator::make($request->all(), $this->rules(required: false))->validate();
        $server = isset($validated['server_uuid']) ? $this->server($teamId, $validated['server_uuid']) : $destination->server;
        if ($server === null) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $destination->fill($this->attributes($validated, $server, required: false));
        $this->validateReplicaRange($destination->min_replicas, $destination->max_replicas);
        $destination->save();

        return response()->json($this->payload($destination->load('server')));
    }

    public function delete_kubernetes(string $uuid)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $destination = KubernetesCluster::ownedByCurrentTeamAPI($teamId)->whereUuid($uuid)->first();
        if ($destination === null) {
            return response()->json(['message' => 'Kubernetes destination not found.'], 404);
        }

        if ($destination->attachedTo()) {
            return response()->json(['message' => 'You must delete all resources before deleting this destination.'], 400);
        }

        $destination->delete();

        return response()->json(['message' => 'Kubernetes destination deleted.']);
    }

    private function findDestination(int $teamId, string $uuid): StandaloneDocker|SwarmDocker|KubernetesCluster|null
    {
        return StandaloneDocker::ownedByCurrentTeamAPI($teamId)->with('server')->whereUuid($uuid)->first()
            ?? SwarmDocker::ownedByCurrentTeamAPI($teamId)->with('server')->whereUuid($uuid)->first()
            ?? KubernetesCluster::ownedByCurrentTeamAPI($teamId)->with('server')->whereUuid($uuid)->first();
    }

    private function server(int $teamId, string $uuid): ?Server
    {
        return Server::whereTeamId($teamId)->whereUuid($uuid)->first();
    }

    private function payload(StandaloneDocker|SwarmDocker|KubernetesCluster $destination): array
    {
        $payload = [
            'uuid' => $destination->uuid,
            'name' => $destination->name,
            'type' => $this->type($destination),
            'server_uuid' => $destination->server?->uuid,
            'created_at' => $destination->created_at,
            'updated_at' => $destination->updated_at,
        ];

        if ($destination instanceof KubernetesCluster) {
            $payload += $destination->only([
                'namespace',
                'create_namespace',
                'context',
                'ingress_class',
                'ingress_tls_secret',
                'ingress_annotations',
                'service_type',
                'service_account_name',
                'create_service_account',
                'image_pull_secrets',
                'storage_class',
                'storage_size',
                'replicas',
                'autoscaling_enabled',
                'min_replicas',
                'max_replicas',
                'target_cpu_utilization_percentage',
                'node_selector',
                'tolerations',
                'pod_disruption_budget_enabled',
                'pod_disruption_budget_min_available',
            ]);

            if (request()->attributes->get('can_read_sensitive', false) === true) {
                $payload['kubeconfig_path'] = $destination->kubeconfig_path;
                $payload['kubeconfig'] = $destination->kubeconfig;
            }

            return serializeApiResponse($payload)->toArray();
        }

        $payload['network'] = $destination->network;

        return serializeApiResponse($payload)->toArray();
    }

    private function type(StandaloneDocker|SwarmDocker|KubernetesCluster $destination): string
    {
        return match ($destination::class) {
            KubernetesCluster::class => 'kubernetes',
            SwarmDocker::class => 'swarm-docker',
            default => 'standalone-docker',
        };
    }

    private function rules(bool $required): array
    {
        $prefix = $required ? 'required' : 'sometimes';

        return [
            'name' => [$prefix, 'string', 'max:255'],
            'server_uuid' => [$prefix, 'string'],
            'namespace' => [$prefix, 'string', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'],
            'create_namespace' => ['sometimes', 'boolean'],
            'context' => ['nullable', 'string', 'max:255'],
            'kubeconfig_path' => ['nullable', 'string', 'max:1024'],
            'kubeconfig' => ['nullable', 'string'],
            'ingress_class' => ['sometimes', 'string', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'],
            'ingress_tls_secret' => ['nullable', 'string', 'max:253', 'regex:/^[a-z0-9]([-a-z0-9.]*[a-z0-9])?$/'],
            'ingress_annotations' => ['nullable', 'string', 'max:10000'],
            'service_type' => ['sometimes', 'string', 'in:ClusterIP,NodePort,LoadBalancer'],
            'service_account_name' => ['nullable', 'string', 'max:253', 'regex:/^[a-z0-9]([-a-z0-9.]*[a-z0-9])?$/'],
            'create_service_account' => ['sometimes', 'boolean'],
            'image_pull_secrets' => ['nullable', 'string', 'max:5000'],
            'storage_class' => ['nullable', 'string', 'max:253'],
            'storage_size' => ['sometimes', 'string', 'max:32', 'regex:/^[1-9][0-9]*(Ei|Pi|Ti|Gi|Mi|Ki|E|P|T|G|M|K)?$/'],
            'replicas' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'autoscaling_enabled' => ['sometimes', 'boolean'],
            'min_replicas' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'max_replicas' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'target_cpu_utilization_percentage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'node_selector' => ['nullable', 'string', 'max:10000'],
            'tolerations' => ['nullable', 'string', 'max:10000'],
            'pod_disruption_budget_enabled' => ['sometimes', 'boolean'],
            'pod_disruption_budget_min_available' => ['nullable', 'string', 'max:16', 'regex:/^\d+%?$/'],
        ];
    }

    private function attributes(array $validated, Server $server, bool $required = true): array
    {
        unset($validated['server_uuid']);
        $validated['server_id'] = $server->id;
        $validated += $required ? [
            'namespace' => 'default',
            'ingress_class' => 'traefik',
            'service_type' => 'ClusterIP',
            'storage_size' => '1Gi',
            'replicas' => 1,
            'min_replicas' => 1,
            'max_replicas' => 3,
            'target_cpu_utilization_percentage' => 70,
        ] : [];

        $this->validateReplicaRange((int) ($validated['min_replicas'] ?? 1), (int) ($validated['max_replicas'] ?? 3));

        return $validated;
    }

    private function validateReplicaRange(int $minReplicas, int $maxReplicas): void
    {
        if ($maxReplicas < $minReplicas) {
            throw ValidationException::withMessages([
                'max_replicas' => 'Max replicas must be greater than or equal to min replicas.',
            ]);
        }
    }
}
