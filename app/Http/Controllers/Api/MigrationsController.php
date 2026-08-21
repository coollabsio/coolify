<?php

namespace App\Http\Controllers\Api;

use App\Actions\Migration\DiscoverResources;
use App\Actions\Migration\ExportResources;
use App\Actions\Migration\ImportResources;
use App\Enums\MigrationStorageDriver;
use App\Enums\ResourceMigrationDirection;
use App\Enums\ResourceMigrationStatus;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ResourceMigration;
use App\Models\Server;
use App\Services\Migration\Manifest;
use App\Services\Migration\Storage\MigrationStorageFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class MigrationsController extends Controller
{
    #[OA\Get(
        summary: 'Preflight',
        description: 'Validate that this Coolify instance can participate in a resource migration.',
        path: '/migrations/preflight',
        operationId: 'migration-preflight',
        security: [['bearerAuth' => []]],
        tags: ['Migrations'],
    )]
    public function preflight(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $this->authorize('create', ResourceMigration::class);

        $token = $request->user()->currentAccessToken();
        $canReadSensitive = $request->attributes->get('can_read_sensitive', false) === true;
        $serverUuid = $request->query('server_uuid');
        $dockerRunning = null;
        $serverReachable = null;

        if (is_string($serverUuid) && $serverUuid !== '') {
            $server = Server::where('team_id', $teamId)->where('uuid', $serverUuid)->first();
            if (! $server) {
                return response()->json(['message' => 'Server not found.'], 404);
            }
            $serverReachable = $server->isFunctional();
            $dockerRunning = $serverReachable;
        }

        return response()->json(serializeApiResponse([
            'version' => config('constants.coolify.version'),
            'manifest_version' => Manifest::VERSION,
            'token_can_write' => $token->can('write') || $token->can('root'),
            'token_can_read_sensitive' => $canReadSensitive,
            'docker_running' => $dockerRunning,
            'server_reachable' => $serverReachable,
        ]));
    }

    #[OA\Get(
        summary: 'List migratable resources',
        description: 'Enumerate applications, databases, and services that can be exported.',
        path: '/migrations/resources',
        operationId: 'migration-resources',
        security: [['bearerAuth' => []]],
        tags: ['Migrations'],
    )]
    public function resources(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $this->authorize('create', ResourceMigration::class);

        $resources = DiscoverResources::run(
            $teamId,
            $request->query('server_uuid'),
            $request->query('project_uuid'),
            $request->query('environment_uuid'),
        );

        return response()->json(serializeApiResponse(collect($resources)));
    }

    #[OA\Post(
        summary: 'Export resources',
        description: 'Start a resource export. Requires a token with write and read:sensitive abilities.',
        path: '/migrations/export',
        operationId: 'migration-export',
        security: [['bearerAuth' => []]],
        tags: ['Migrations'],
    )]
    public function export(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $this->authorize('create', ResourceMigration::class);

        if ($request->attributes->get('can_read_sensitive', false) !== true) {
            return response()->json([
                'message' => 'Exporting resources requires a token with the read:sensitive ability.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'resource_uuids' => ['required', 'array', 'min:1'],
            'resource_uuids.*' => ['required', 'string'],
            'storage.driver' => ['required', 'string'],
            'storage.config' => ['nullable', 'array'],
            'skip_data' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        try {
            $driver = MigrationStorageDriver::fromAlias($request->input('storage.driver'));
        } catch (\ValueError) {
            return response()->json(['message' => 'Unsupported storage driver.'], 422);
        }

        $items = [];
        foreach ($request->input('resource_uuids') as $index => $uuid) {
            $resource = getResourceByUuid($uuid, $teamId);
            if (! $resource) {
                return response()->json(['message' => "Resource {$uuid} was not found."], 404);
            }
            $items[] = [
                'resource_type' => $resource->type(),
                'source_uuid' => $resource->uuid,
                'name' => $resource->name,
                'sort_order' => $this->sortOrder($resource->type(), $index),
                'status' => ResourceMigrationStatus::Pending,
            ];
        }

        usort($items, fn (array $left, array $right): int => $left['sort_order'] <=> $right['sort_order']);

        $migration = ResourceMigration::create([
            'team_id' => $teamId,
            'direction' => ResourceMigrationDirection::Export,
            'status' => ResourceMigrationStatus::Pending,
            'storage_driver' => $driver,
            'storage_config' => $request->input('storage.config', []),
            'skip_data' => (bool) $request->boolean('skip_data'),
            'created_by_user_id' => $request->user()->id,
        ]);
        $migration->items()->createMany($items);

        ExportResources::dispatch($migration);

        return response()->json(serializeApiResponse($this->payload($migration->fresh('items'))), 201);
    }

    #[OA\Get(
        summary: 'Show migration',
        description: 'Get migration status and per-resource progress.',
        path: '/migrations/{uuid}',
        operationId: 'migration-show',
        security: [['bearerAuth' => []]],
        tags: ['Migrations'],
    )]
    public function show(string $uuid): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $migration = ResourceMigration::where('team_id', $teamId)->where('uuid', $uuid)->with('items')->first();
        if (! $migration) {
            return response()->json(['message' => 'Migration not found.'], 404);
        }

        $this->authorize('view', $migration);

        return response()->json(serializeApiResponse($this->payload($migration)));
    }

    #[OA\Post(
        summary: 'Import resources',
        description: 'Import a migration manifest onto this Coolify instance.',
        path: '/migrations/import',
        operationId: 'migration-import',
        security: [['bearerAuth' => []]],
        tags: ['Migrations'],
    )]
    public function import(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $this->authorize('create', ResourceMigration::class);

        $validator = Validator::make($request->all(), [
            'manifest' => ['required', 'array'],
            'manifest.version' => ['required', 'integer'],
            'manifest.resources' => ['required', 'array', 'min:1'],
            'destination_uuid' => ['required', 'string'],
            'project_uuid' => ['required', 'string'],
            'environment_uuid' => ['nullable', 'string'],
            'project_name' => ['nullable', 'string'],
            'environment_name' => ['nullable', 'string'],
            'storage.driver' => ['nullable', 'string'],
            'storage.config' => ['nullable', 'array'],
            'skip_data' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $destination = find_destination_for_team($request->input('destination_uuid'), $teamId);
        if (! $destination) {
            return response()->json(['message' => 'Destination not found.'], 404);
        }
        if (! $destination->server?->canHostResources()) {
            return response()->json(['message' => 'The selected server cannot host resources.'], 422);
        }

        $project = $this->resolveProject($teamId, $request);
        $environment = $this->resolveEnvironment($project, $request);

        $driverValue = $request->input('storage.driver', data_get($request->input('manifest'), 'storage.driver', 's3'));
        try {
            $driver = MigrationStorageDriver::fromAlias((string) $driverValue);
        } catch (\ValueError) {
            return response()->json(['message' => 'Unsupported storage driver.'], 422);
        }

        $manifest = $request->input('manifest');
        $skipData = $request->has('skip_data')
            ? $request->boolean('skip_data')
            : (bool) data_get($manifest, 'skip_data', false);

        $migration = ResourceMigration::create([
            'team_id' => $teamId,
            'direction' => ResourceMigrationDirection::Import,
            'status' => ResourceMigrationStatus::Pending,
            'storage_driver' => $driver,
            'storage_config' => $request->input('storage.config', data_get($manifest, 'storage.config', [])),
            'manifest' => $manifest,
            'skip_data' => $skipData,
            'destination_uuid' => $destination->uuid,
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'created_by_user_id' => $request->user()->id,
        ]);

        foreach (array_values($manifest['resources']) as $index => $resource) {
            $migration->items()->create([
                'resource_type' => $resource['type'] ?? 'unknown',
                'source_uuid' => $resource['source_uuid'] ?? new_public_id(),
                'name' => $resource['name'] ?? 'resource',
                'sort_order' => $this->sortOrder((string) ($resource['type'] ?? ''), $index),
                'status' => ResourceMigrationStatus::Pending,
            ]);
        }

        ImportResources::dispatch($migration);

        return response()->json(serializeApiResponse($this->payload($migration->fresh('items'))), 201);
    }

    #[OA\Post(
        summary: 'Cleanup migration staging',
        description: 'Delete staging archives for a completed or failed migration.',
        path: '/migrations/{uuid}/cleanup',
        operationId: 'migration-cleanup',
        security: [['bearerAuth' => []]],
        tags: ['Migrations'],
    )]
    public function cleanup(string $uuid): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $migration = ResourceMigration::where('team_id', $teamId)->where('uuid', $uuid)->with('items')->first();
        if (! $migration) {
            return response()->json(['message' => 'Migration not found.'], 404);
        }

        $this->authorize('update', $migration);

        $storage = app(MigrationStorageFactory::class)->forMigration($migration);
        $server = Server::where('team_id', $teamId)->get()->first(fn (Server $server): bool => $server->isFunctional());

        if ($server) {
            foreach ($migration->items as $item) {
                foreach ($item->archives ?? [] as $archive) {
                    if (filled($archive['key'] ?? null)) {
                        $storage->delete($server, (string) $archive['key']);
                    }
                }
            }
        }

        return response()->json(['message' => 'Migration staging cleaned up.']);
    }

    private function resolveProject(int $teamId, Request $request): Project
    {
        $project = Project::where('team_id', $teamId)->where('uuid', $request->input('project_uuid'))->first();
        if ($project) {
            return $project;
        }

        return Project::create([
            'uuid' => $request->input('project_uuid'),
            'name' => $request->input('project_name', 'Migrated Project'),
            'team_id' => $teamId,
        ]);
    }

    private function resolveEnvironment(Project $project, Request $request): Environment
    {
        $uuid = $request->input('environment_uuid');
        if (is_string($uuid) && $uuid !== '') {
            $environment = $project->environments()->where('uuid', $uuid)->first();
            if ($environment) {
                return $environment;
            }

            return $project->environments()->create([
                'uuid' => $uuid,
                'name' => $request->input('environment_name', 'production'),
            ]);
        }

        $environment = $project->environments()->first();
        if ($environment) {
            return $environment;
        }

        return $project->environments()->create([
            'name' => $request->input('environment_name', 'production'),
        ]);
    }

    private function sortOrder(string $type, int $index): int
    {
        $rank = match (true) {
            str_starts_with($type, 'standalone') => 0,
            $type === 'service' => 1,
            default => 2,
        };

        return ($rank * 1000) + $index;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ResourceMigration $migration): array
    {
        $includeManifest = request()->attributes->get('can_read_sensitive', false) === true
            && $migration->direction === ResourceMigrationDirection::Export;

        return [
            'uuid' => $migration->uuid,
            'direction' => $migration->direction->value,
            'status' => $migration->status->value,
            'skip_data' => $migration->skip_data,
            'storage_driver' => $migration->storage_driver->value,
            'destination_uuid' => $migration->destination_uuid,
            'project_uuid' => $migration->project_uuid,
            'environment_uuid' => $migration->environment_uuid,
            'error' => $migration->error,
            'manifest' => $includeManifest ? $migration->manifest : null,
            'items' => $migration->items->map(fn ($item) => [
                'uuid' => $item->uuid,
                'resource_type' => $item->resource_type,
                'source_uuid' => $item->source_uuid,
                'target_uuid' => $item->target_uuid,
                'name' => $item->name,
                'status' => $item->status->value,
                'error' => $item->error,
            ])->all(),
        ];
    }
}
