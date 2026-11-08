<?php

use App\Actions\Application\GetRollbackImages;
use App\Enums\ApplicationDeploymentStatus;
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
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    $team = Team::factory()->create();
    // The server is not reachable, so only registry images are listed and no SSH command runs.
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'rollback-test']);
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'nixpacks',
        'docker_registry_image_name' => 'ghcr.io/coollabs/app',
    ]);
});

function rollbackDeployment(Application $application, string $commit, string $finishedAt, array $attributes = []): void
{
    ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => fake()->uuid(),
        'commit' => $commit,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'pull_request_id' => 0,
        ...$attributes,
    ])->forceFill(['updated_at' => $finishedAt])->saveQuietly();
}

it('lists the commits of successful deployments as registry images, newest first and once per commit', function () {
    rollbackDeployment($this->application, 'aaaaaaa', '2026-10-01 10:00:00');
    rollbackDeployment($this->application, 'bbbbbbb', '2026-10-02 10:00:00');
    rollbackDeployment($this->application, 'aaaaaaa', '2026-10-03 10:00:00');
    rollbackDeployment($this->application, 'ccccccc', '2026-10-04 10:00:00', ['status' => ApplicationDeploymentStatus::FAILED->value]);
    rollbackDeployment($this->application, 'ddddddd', '2026-10-04 10:00:00', ['pull_request_id' => 5]);
    rollbackDeployment($this->application, 'HEAD', '2026-10-04 10:00:00');

    $result = GetRollbackImages::run($this->application);

    expect($result['current'])->toBeNull()
        ->and($result['images'])->toBe([
            ['tag' => 'aaaaaaa', 'created_at' => '2026-10-03 10:00:00', 'is_current' => false, 'source' => 'registry'],
            ['tag' => 'bbbbbbb', 'created_at' => '2026-10-02 10:00:00', 'is_current' => false, 'source' => 'registry'],
        ]);
});

it('limits the registry images to the most recent deployments', function () {
    foreach (range(1, GetRollbackImages::REGISTRY_DEPLOYMENTS_LIMIT + 2) as $day) {
        rollbackDeployment($this->application, sprintf('%07d', $day), sprintf('2026-10-%02d 10:00:00', $day));
    }

    $images = GetRollbackImages::run($this->application)['images'];

    expect($images)->toHaveCount(GetRollbackImages::REGISTRY_DEPLOYMENTS_LIMIT)
        ->and($images[0]['tag'])->toBe(sprintf('%07d', GetRollbackImages::REGISTRY_DEPLOYMENTS_LIMIT + 2));
});

it('lists no registry images when the application does not push commit images', function (array $attributes) {
    $this->application->update($attributes);
    rollbackDeployment($this->application, 'aaaaaaa', '2026-10-01 10:00:00');

    expect(GetRollbackImages::run($this->application->fresh())['images'])->toBe([]);
})->with([
    'no registry image name' => [['docker_registry_image_name' => null]],
    'docker image build pack' => [['build_pack' => 'dockerimage']],
    'docker compose build pack' => [['build_pack' => 'dockercompose']],
    'dockerfile without git' => [['build_pack' => 'dockerfile', 'dockerfile' => 'FROM nginx']],
]);

describe('on a reachable server', function () {
    beforeEach(function () {
        Server::flushIdentityMap();
        InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://coolify.test']);
        $server = $this->application->destination->server;
        $server->update(['private_key_id' => PrivateKey::factory()->create(['team_id' => $server->team_id])->id]);
        $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
        $this->application->update(['docker_registry_image_name' => null]);
        $this->application = $this->application->fresh();
    });

    function fakeRollbackServer(Application $application, array $containers, ?array $images = null): void
    {
        $uuid = $application->uuid;
        $ps = collect($containers)->map(fn (array $container) => json_encode([
            'ID' => $container['name'],
            'Names' => $container['name'],
            'Image' => $container['image'],
            'State' => $container['state'] ?? 'running',
            'Labels' => "coolify.applicationUuid={$uuid},coolify.pullRequestId=".($container['pr'] ?? 0).',coolify.managed=true',
        ]))->implode(PHP_EOL);
        $images = implode(PHP_EOL, $images ?? ["{$uuid}#aaaaaaa#2026-10-01 10:00:00", "{$uuid}#bbbbbbb#2026-10-02 10:00:00"]);

        Process::fake(fn ($process) => Process::result(output: str_contains(remoteProcessScript($process), 'docker images') ? $images : $ps));
    }

    it('finds the current image of containers that are not named after the application uuid', function (string $name) {
        fakeRollbackServer($this->application, [['name' => $name, 'image' => "{$this->application->uuid}:bbbbbbb"]]);

        $result = GetRollbackImages::run($this->application);

        expect($result['current'])->toBe('bbbbbbb')
            ->and(collect($result['images'])->pluck('is_current', 'tag')->all())->toBe(['aaaaaaa' => false, 'bbbbbbb' => true]);
    })->with([
        'timestamped name' => ['app-20261001T100000'],
        'custom prefix' => ['example-app-20261001T100000'],
        'custom internal name' => ['example-app'],
    ]);

    it('ignores stopped and preview containers', function () {
        $uuid = $this->application->uuid;
        fakeRollbackServer($this->application, [
            ['name' => 'example-app', 'image' => "{$uuid}:bbbbbbb"],
            ['name' => 'example-app-old', 'image' => "{$uuid}:aaaaaaa", 'state' => 'exited'],
            ['name' => "{$uuid}-pr-1", 'image' => "{$uuid}:pr-1", 'pr' => 1],
        ]);

        expect(GetRollbackImages::run($this->application)['current'])->toBe('bbbbbbb');
    });

    it('reports no current image when running containers use different tags', function () {
        $uuid = $this->application->uuid;
        fakeRollbackServer($this->application, [
            ['name' => 'example-app-20261001T100000', 'image' => "{$uuid}:aaaaaaa"],
            ['name' => 'example-app-20261002T100000', 'image' => "{$uuid}:bbbbbbb"],
        ]);

        expect(GetRollbackImages::run($this->application)['current'])->toBeNull();
    });

    it('lists the commit images of Docker Compose services once per commit', function () {
        $uuid = $this->application->uuid;
        $this->application->update([
            'build_pack' => 'dockercompose',
            'docker_compose_raw' => "services:\n  app:\n    build: .\n  worker:\n    build: ./worker\n  db:\n    image: postgres:16\n",
        ]);
        fakeRollbackServer($this->application, [
            ['name' => "app-{$uuid}", 'image' => "{$uuid}_app:bbbbbbb"],
            ['name' => "worker-{$uuid}", 'image' => "{$uuid}_worker:bbbbbbb"],
            ['name' => "db-{$uuid}", 'image' => 'postgres:16'],
        ], [
            "{$uuid}_app#bbbbbbb#2026-10-02 10:00:05",
            "{$uuid}_worker#bbbbbbb#2026-10-02 10:00:00",
            "{$uuid}_app#aaaaaaa#2026-10-01 10:00:00",
            "{$uuid}_worker#aaaaaaa#2026-10-01 10:00:00",
            // Not a service of this application, the application image itself, or another application.
            "{$uuid}_other#ccccccc#2026-10-03 10:00:00",
            "{$uuid}#ddddddd#2026-10-03 10:00:00",
            "{$uuid}x_app#eeeeeee#2026-10-03 10:00:00",
            'postgres#16#2026-09-01 10:00:00',
        ]);

        $result = GetRollbackImages::run($this->application->fresh());

        expect($result['current'])->toBe('bbbbbbb')
            ->and($result['images'])->toBe([
                ['tag' => 'bbbbbbb', 'created_at' => '2026-10-02 10:00:05', 'is_current' => true, 'source' => 'local'],
                ['tag' => 'aaaaaaa', 'created_at' => '2026-10-01 10:00:00', 'is_current' => false, 'source' => 'local'],
            ]);
    });
});

it('allows a rollback only to a listed image or a successful production deployment', function () {
    rollbackDeployment($this->application, 'aaaaaaa', '2026-10-01 10:00:00');
    rollbackDeployment($this->application, 'bbbbbbb', '2026-10-02 10:00:00', ['pull_request_id' => 3]);

    expect(GetRollbackImages::allows($this->application, 'aaaaaaa'))->toBeTrue()
        ->and(GetRollbackImages::allows($this->application, 'bbbbbbb'))->toBeFalse()
        ->and(GetRollbackImages::allows($this->application, str_repeat('f', 40)))->toBeFalse();
});
