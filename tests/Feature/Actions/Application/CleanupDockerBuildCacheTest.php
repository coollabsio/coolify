<?php

namespace App\Actions\Application {
    function instant_remote_process($commands, $server, $throwError = true): string
    {
        $GLOBALS['dockerBuildCacheCleanupCalls'][] = [
            'commands' => $commands,
            'server_id' => $server->id,
            'throw_error' => $throwError,
        ];

        return '';
    }
}

namespace {
    use App\Actions\Application\CleanupDockerBuildCache;
    use App\Models\Application;
    use App\Models\ApplicationDeploymentQueue;
    use App\Models\Environment;
    use App\Models\Project;
    use App\Models\Server;
    use App\Models\Team;
    use Illuminate\Foundation\Testing\RefreshDatabase;

    uses(RefreshDatabase::class);

    beforeEach(function () {
        $GLOBALS['dockerBuildCacheCleanupCalls'] = [];
    });

    test('application deletion removes local build cache from deployment and previous build servers', function () {
        $team = Team::factory()->create();
        $deploymentServer = Server::factory()->create(['team_id' => $team->id]);
        $buildServer = Server::factory()->create(['team_id' => $team->id]);
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $destination = $deploymentServer->standaloneDockers()->firstOrFail();
        $application = Application::factory()->create([
            'uuid' => 'application-uuid',
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);

        ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'deployment_uuid' => 'deployment-uuid',
            'server_id' => $deploymentServer->id,
            'destination_id' => $destination->id,
            'build_server_id' => $buildServer->id,
        ]);

        CleanupDockerBuildCache::run($application);

        expect($GLOBALS['dockerBuildCacheCleanupCalls'])->toHaveCount(2);

        foreach ($GLOBALS['dockerBuildCacheCleanupCalls'] as $call) {
            expect($call['commands'])->toBe([
                "rm -rf -- '/data/coolify/docker-build-cache/application-uuid'",
            ])->and($call['throw_error'])->toBeFalse();
        }

        expect(collect($GLOBALS['dockerBuildCacheCleanupCalls'])->pluck('server_id')->sort()->values()->all())
            ->toBe(collect([$deploymentServer->id, $buildServer->id])->sort()->values()->all());
    });
}
