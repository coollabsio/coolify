<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    Notification::fake();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    Process::fake(['*' => Process::result(output: '')]);
    config([
        'constants.ssh.mux_enabled' => false,
        'cache.default' => 'array',
        'app.maintenance.store' => 'array',
    ]);

    $settings = new InstanceSettings;
    $settings->id = 0;
    $settings->save();

    $this->team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $ip = 40;

    $this->makeServer = function (int $concurrentBuilds, string $role = 'both') use ($privateKey, &$ip): Server {
        $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id, 'ip' => '203.0.113.'.$ip++]);
        $server->settings->update([
            'concurrent_builds' => $concurrentBuilds,
            'server_role' => $role,
            'is_build_server' => $role === 'build',
            'is_reachable' => true,
            'is_usable' => true,
        ]);

        return $server->fresh();
    };

    $this->makeApplication = function (Server $server, bool $usesBuildServer = true) use ($environment): Application {
        $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
        $application = Application::factory()->create([
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
        $application->settings->update(['is_build_server_enabled' => $usesBuildServer]);

        return $application->fresh();
    };

    $this->deploy = function (Application $application): ApplicationDeploymentQueue {
        $uuid = new_public_id();
        queue_application_deployment($application, $uuid);

        return ApplicationDeploymentQueue::where('deployment_uuid', $uuid)->firstOrFail();
    };
});

afterEach(function () {
    Server::flushIdentityMap();
});

test('the deployment server limit does not cap builds that run on a build server', function () {
    $deploymentServer = ($this->makeServer)(1);
    $buildServer = ($this->makeServer)(2, 'build');

    $first = ($this->deploy)(($this->makeApplication)($deploymentServer));
    $second = ($this->deploy)(($this->makeApplication)($deploymentServer));
    $third = ($this->deploy)(($this->makeApplication)($deploymentServer));

    expect($first->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($first->build_server_id)->toBe($buildServer->id)
        ->and($second->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($second->build_server_id)->toBe($buildServer->id)
        ->and($third->status)->toBe(ApplicationDeploymentStatus::QUEUED->value)
        ->and($third->build_server_id)->toBeNull();
});

test('the build server limit holds for deployments of different deployment servers', function () {
    $buildServer = ($this->makeServer)(1, 'build');

    $first = ($this->deploy)(($this->makeApplication)(($this->makeServer)(5)));
    $second = ($this->deploy)(($this->makeApplication)(($this->makeServer)(5)));

    expect($first->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($first->build_server_id)->toBe($buildServer->id)
        ->and($second->status)->toBe(ApplicationDeploymentStatus::QUEUED->value);
    Queue::assertPushed(ApplicationDeploymentJob::class, 1);
});

test('a deployment reserves the build server that has a free build slot', function () {
    $deploymentServer = ($this->makeServer)(5);
    $buildServers = collect([($this->makeServer)(1, 'build'), ($this->makeServer)(1, 'build')]);

    $first = ($this->deploy)(($this->makeApplication)($deploymentServer));
    $second = ($this->deploy)(($this->makeApplication)($deploymentServer));

    expect([$first->build_server_id, $second->build_server_id])
        ->toEqualCanonicalizing($buildServers->pluck('id')->all())
        ->and($second->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});

test('a finished build starts the deployment of another deployment server that waits for the build server', function () {
    $buildServer = ($this->makeServer)(1, 'build');
    $running = ($this->deploy)(($this->makeApplication)(($this->makeServer)(5)));
    $waiting = ($this->deploy)(($this->makeApplication)(($this->makeServer)(5)));
    expect($waiting->status)->toBe(ApplicationDeploymentStatus::QUEUED->value);

    (new ApplicationDeploymentJob($running->id))->failed(new RuntimeException('build failed'));

    $waiting->refresh();
    expect($waiting->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($waiting->build_server_id)->toBe($buildServer->id);
    Queue::assertPushed(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $job) => $job->application_deployment_queue_id === $waiting->id);
});

test('builds on a build server do not take the build slots of the deployment server', function () {
    $deploymentServer = ($this->makeServer)(1);
    ($this->makeServer)(1, 'build');

    $onBuildServer = ($this->deploy)(($this->makeApplication)($deploymentServer));
    $onDeploymentServer = ($this->deploy)(($this->makeApplication)($deploymentServer, usesBuildServer: false));
    $waiting = ($this->deploy)(($this->makeApplication)($deploymentServer, usesBuildServer: false));

    expect($onBuildServer->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($onDeploymentServer->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($onDeploymentServer->build_server_id)->toBeNull()
        ->and($waiting->status)->toBe(ApplicationDeploymentStatus::QUEUED->value);
});
