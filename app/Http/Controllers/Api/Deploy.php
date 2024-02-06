<?php

namespace App\Http\Controllers\Api;

use App\Actions\Database\StartMariadb;
use App\Actions\Database\StartMongodb;
use App\Actions\Database\StartMysql;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StartRedis;
use App\Actions\Service\StartService;
use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Visus\Cuid2\Cuid2;

class Deploy extends Controller
{
    public function deploy(Request $request)
    {
        $token = auth()->user()->currentAccessToken();
        $teamId = data_get($token, 'team_id');
        $uuids = $request->query->get('uuid');
        $tags = $request->query->get('tag');
        $force = $request->query->get('force') ?? false;

        if ($uuids && $tags) {
            return response()->json(['error' => 'You can only use uuid or tag, not both.', 'docs' => 'https://coolify.io/docs/api/deploy-webhook'], 400);
        }
        if (is_null($teamId)) {
            return response()->json(['error' => 'Invalid token.', 'docs' => 'https://coolify.io/docs/api/authentication'], 400);
        }
        if ($tags) {
            return $this->by_tags($tags, $teamId, $force);
        } else if ($uuids) {
            return $this->by_uuids($uuids, $teamId, $force);
        }
        return response()->json(['error' => 'You must provide uuid or tag.', 'docs' => 'https://coolify.io/docs/api/deploy-webhook'], 400);
    }
    private function by_uuids(string $uuid, int $teamId, bool $force = false)
    {
        $uuids = explode(',', $uuid);
        $uuids = collect(array_filter($uuids));

        if (count($uuids) === 0) {
            return response()->json(['error' => 'No UUIDs provided.', 'docs' => 'https://coolify.io/docs/api/deploy-webhook'], 400);
        }
        $message = collect([]);
        foreach ($uuids as $uuid) {
            $resource = getResourceByUuid($uuid, $teamId);
            if ($resource) {
                $return_message = $this->deploy_resource($resource, $force);
                $message = $message->merge($return_message);
            }
        }
        if ($message->count() > 0) {
            return response()->json(['message' => $message->toArray()], 200);
        }
        return response()->json(['error' => "No resources found.", 'docs' => 'https://coolify.io/docs/api/deploy-webhook'], 404);
    }
    public function by_tags(string $tags, int $team_id, bool $force = false)
    {
        $tags = explode(',', $tags);
        $tags = collect(array_filter($tags));

        if (count($tags) === 0) {
            return response()->json(['error' => 'No TAGs provided.', 'docs' => 'https://coolify.io/docs/api/deploy-webhook'], 400);
        }
        $message = collect([]);
        foreach ($tags as $tag) {
            $found_tag = Tag::where(['name' => $tag, 'team_id' => $team_id])->first();
            if (!$found_tag) {
                $message->push("Tag {$tag} not found.");
                continue;
            }
            $applications = $found_tag->applications();
            $services = $found_tag->services();
            if ($applications->count() === 0 && $services->count() === 0) {
                $message->push("No resources found for tag {$tag}.");
                continue;
            }
            foreach ($applications as $resource) {
                $return_message = $this->deploy_resource($resource, $force);
                $message = $message->merge($return_message);
            }
            foreach ($services as $resource) {
                $return_message = $this->deploy_resource($resource, $force);
                $message = $message->merge($return_message);
            }
        }
        if ($message->count() > 0) {
            return response()->json(['message' => $message->toArray()], 200);
        }

        return response()->json(['error' => "No resources found.", 'docs' => 'https://coolify.io/docs/api/deploy-webhook'], 404);
    }
    public function deploy_resource($resource, bool $force = false): Collection
    {
        $message = collect([]);
        $type = $resource?->getMorphClass();
        if ($type === 'App\Models\Application') {
            queue_application_deployment(
                application: $resource,
                deployment_uuid: new Cuid2(7),
                force_rebuild: $force,
            );
            $message->push("Application {$resource->name} deployment queued.");
        } else if ($type === 'App\Models\StandalonePostgresql') {
            if (str($resource->status)->startsWith('running')) {
                $message->push("Database {$resource->name} already running.");
            }
            StartPostgresql::run($resource);
            $resource->update([
                'started_at' => now(),
            ]);
            $message->push("Database {$resource->name} started.");
        } else if ($type === 'App\Models\StandaloneRedis') {
            if (str($resource->status)->startsWith('running')) {
                $message->push("Database {$resource->name} already running.");
            }
            StartRedis::run($resource);
            $resource->update([
                'started_at' => now(),
            ]);
            $message->push("Database {$resource->name} started.");
        } else if ($type === 'App\Models\StandaloneMongodb') {
            if (str($resource->status)->startsWith('running')) {
                $message->push("Database {$resource->name} already running.");
            }
            StartMongodb::run($resource);
            $resource->update([
                'started_at' => now(),
            ]);
            $message->push("Database {$resource->name} started.");
        } else if ($type === 'App\Models\StandaloneMysql') {
            if (str($resource->status)->startsWith('running')) {
                $message->push("Database {$resource->name} already running.");
            }
            StartMysql::run($resource);
            $resource->update([
                'started_at' => now(),
            ]);
            $message->push("Database {$resource->name} started.");
        } else if ($type === 'App\Models\StandaloneMariadb') {
            if (str($resource->status)->startsWith('running')) {
                $message->push("Database {$resource->name} already running.");
            }
            StartMariadb::run($resource);
            $resource->update([
                'started_at' => now(),
            ]);
            $message->push("Database {$resource->name} started.");
        } else if ($type === 'App\Models\Service') {
            StartService::run($resource);
            $message->push("Service {$resource->name} started. It could take a while, be patient.");
        }
        return $message;
    }
}
