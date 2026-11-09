<?php

namespace App\Services\ProjectConfig;

use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Service\RestartService;
use App\Actions\Service\StartService;
use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentConfigRevision;
use Illuminate\Database\Eloquent\Model;

/**
 * Deployments of a push that wait until the file of the same push is applied.
 */
class HeldDeployments
{
    public function deploy(Application $application, ?string $commit): void
    {
        queue_application_deployment(
            application: $application,
            deployment_uuid: new_public_id(),
            commit: $commit,
            is_webhook: true,
        );
    }

    /**
     * Starts a new database or service, or restarts a changed one.
     */
    public function restart(Model $resource, bool $created): void
    {
        $database = str_starts_with($resource::class, 'App\\Models\\Standalone');
        match (true) {
            $database && $created => StartDatabase::dispatch($resource),
            $database => RestartDatabase::dispatch($resource),
            $created => StartService::dispatch($resource),
            default => RestartService::dispatch($resource, false),
        };
    }

    /**
     * Deploys the held applications and the changed applications, each one time.
     *
     * @param  list<Application>  $changed
     * @param  list<string>  $skipUuids  applications that wait for a required value
     */
    public function release(EnvironmentConfigRevision $revision, array $changed, array $skipUuids = []): void
    {
        $commits = [];
        foreach ($changed as $application) {
            $commits[$application->uuid] = null;
        }
        foreach ($revision->held_deployments ?? [] as $held) {
            $commits[$held['application_uuid']] = $held['commit_sha'] ?? null;
        }

        foreach ($commits as $uuid => $commit) {
            if (in_array($uuid, $skipUuids, true)) {
                continue;
            }
            $application = Application::query()->where('uuid', $uuid)->first();
            if ($application !== null) {
                $this->deploy($application, $commit);
            }
        }
    }

    /**
     * Records a failed deployment for each held application, with the reason.
     */
    public function fail(EnvironmentConfigRevision $revision, string $reason): void
    {
        foreach ($revision->held_deployments ?? [] as $held) {
            $application = Application::query()->where('uuid', $held['application_uuid'])->first();
            if ($application === null) {
                continue;
            }
            $server = $application->destination?->server;
            $deployment = ApplicationDeploymentQueue::create([
                'application_id' => $application->id,
                'application_name' => $application->name,
                'server_id' => $server?->id,
                'server_name' => $server?->name,
                'destination_id' => $application->destination_id,
                'deployment_uuid' => new_public_id(),
                'commit' => $held['commit_sha'] ?? 'HEAD',
                'is_webhook' => true,
                'status' => ApplicationDeploymentStatus::FAILED->value,
            ]);
            $deployment->addLogEntry("coolify.json was not applied: {$reason}", 'stderr');
        }
    }
}
