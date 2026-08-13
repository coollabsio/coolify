<?php

namespace App\Actions\Migration;

use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Jobs\VolumeCloneJob;
use App\Models\Application;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\SwarmDocker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class CloneResourceToDestination
{
    use AsAction;

    public function handle(Model $resource, StandaloneDocker|SwarmDocker $destination, bool $cloneVolumeData = true): Model
    {
        if ($resource instanceof Application) {
            return clone_application($resource, $destination, [], $cloneVolumeData);
        }

        if ($this->isStandaloneDatabase($resource)) {
            return $this->cloneDatabase($resource, $destination, $cloneVolumeData);
        }

        if ($resource instanceof Service) {
            return $this->cloneService($resource, $destination, $cloneVolumeData);
        }

        throw new RuntimeException('Unsupported resource type ['.$resource->type().'].');
    }

    private function isStandaloneDatabase(Model $resource): bool
    {
        return $resource instanceof StandalonePostgresql
            || $resource instanceof StandaloneMongodb
            || $resource instanceof StandaloneMysql
            || $resource instanceof StandaloneMariadb
            || $resource instanceof StandaloneRedis
            || $resource instanceof StandaloneKeydb
            || $resource instanceof StandaloneDragonfly
            || $resource instanceof StandaloneClickhouse;
    }

    private function cloneDatabase(Model $resource, StandaloneDocker|SwarmDocker $destination, bool $cloneVolumeData): Model
    {
        $cloneResult = clone_standalone_database($resource, $destination, $cloneVolumeData);
        $newResource = $cloneResult['database'];
        $volumesToClone = $cloneResult['volume_pairs'];

        if ($cloneVolumeData && $volumesToClone !== []) {
            $jobs = [StopDatabase::makeJob($resource, false)];
            foreach ($volumesToClone as [$sourceVolume, $targetVolume]) {
                $jobs[] = new VolumeCloneJob(
                    $sourceVolume->name,
                    $targetVolume->name,
                    $resource->destination->server,
                    $newResource->destination->server,
                    $targetVolume,
                );
            }
            $jobs[] = StartDatabase::makeJob($resource);
            Bus::chain($jobs)->onQueue('high')->dispatch();
        }

        return $newResource;
    }

    private function cloneService(Service $resource, StandaloneDocker|SwarmDocker $destination, bool $cloneVolumeData): Service
    {
        $uuid = new_public_id();
        $newResource = $resource->replicate([
            'id',
            'created_at',
            'updated_at',
        ])->fill([
            'uuid' => $uuid,
            'name' => $resource->name.'-clone-'.$uuid,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'server_id' => $destination->server_id,
        ]);
        $newResource->save();

        foreach ($resource->tags as $tag) {
            $newResource->tags()->attach($tag->id);
        }

        foreach ($resource->scheduled_tasks()->get() as $task) {
            $task->replicate(['id', 'created_at', 'updated_at'])->fill([
                'uuid' => new_public_id(),
                'service_id' => $newResource->id,
                'team_id' => currentTeam()->id,
            ])->save();
        }

        foreach ($resource->environment_variables()->get() as $environmentVariable) {
            $environmentVariable->replicate(['id', 'created_at', 'updated_at'])->fill([
                'resourceable_id' => $newResource->id,
                'resourceable_type' => $newResource->getMorphClass(),
            ])->save();
        }

        $newResource->parse();
        $newResource->refresh();

        $volumesToClone = [];
        foreach ($resource->applications as $sourceApplication) {
            $newApplication = $newResource->applications()->where('name', $sourceApplication->name)->first();
            if (! $newApplication) {
                continue;
            }
            $newApplication->fill(['status' => 'exited'])->save();
            if ($cloneVolumeData) {
                foreach ($sourceApplication->persistentStorages as $sourceVolume) {
                    $targetVolume = $newApplication->persistentStorages()->where('mount_path', $sourceVolume->mount_path)->first();
                    if ($targetVolume) {
                        $volumesToClone[] = [$sourceVolume, $targetVolume];
                    }
                }
            }
        }

        foreach ($resource->databases as $sourceDatabase) {
            $newDatabase = $newResource->databases()->where('name', $sourceDatabase->name)->first();
            if (! $newDatabase) {
                continue;
            }
            $newDatabase->fill(['status' => 'exited'])->save();
            if ($cloneVolumeData) {
                foreach ($sourceDatabase->persistentStorages as $sourceVolume) {
                    $targetVolume = $newDatabase->persistentStorages()->where('mount_path', $sourceVolume->mount_path)->first();
                    if ($targetVolume) {
                        $volumesToClone[] = [$sourceVolume, $targetVolume];
                    }
                }
            }
        }

        if ($cloneVolumeData && $volumesToClone !== []) {
            $jobs = [StopService::makeJob($resource, false, false)];
            foreach ($volumesToClone as [$sourceVolume, $targetVolume]) {
                $jobs[] = new VolumeCloneJob(
                    $sourceVolume->name,
                    $targetVolume->name,
                    $resource->destination->server,
                    $newResource->destination->server,
                    $targetVolume,
                );
            }
            $jobs[] = StartService::makeJob($resource);
            Bus::chain($jobs)->onQueue('high')->dispatch();
        }

        return $newResource;
    }
}
