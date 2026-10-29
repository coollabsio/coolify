<?php

use App\Actions\Database\StartPostgresql;
use App\Actions\Service\StartService;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\DatabaseStartCommandExecutor;
use App\Services\ImageScan\ImageStartPin;
use App\Support\RemoteProcessCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const START_PIN_DIGEST = 'sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

beforeEach(function () {
    Server::flushIdentityMap();
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    InstanceSettings::forceCreate(['id' => 0]);
    Process::fake();
    Queue::fake();

    $team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);

    // Keeps the commands of a database start instead of running them.
    $this->databaseCommands = [];
    app()->instance(DatabaseStartCommandExecutor::class, new class
    {
        public function execute(array $commands, $database, Activity $activity): Activity
        {
            test()->databaseCommands = $commands;

            return $activity;
        }
    });
});

describe('pins', function () {
    it('keeps the scanned digest per image until a start takes it', function () {
        $database = create_standalone_postgresql($this->environment->id, $this->destination);

        ImageStartPin::put($database, 'postgres:16-alpine', START_PIN_DIGEST);

        expect(ImageStartPin::take($database))->toBe(['docker.io/library/postgres:16-alpine' => [
            'ref' => 'postgres:16-alpine', 'pull' => 'docker.io/library/postgres@'.START_PIN_DIGEST,
        ]])->and(ImageStartPin::take($database))->toBe([]);
    });

    it('refuses an image pinned by digest or a digest that is not one', function (string $ref, string $digest) {
        expect(fn () => ImageStartPin::put(create_standalone_postgresql($this->environment->id, $this->destination), $ref, $digest))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'image with a digest' => ['postgres@'.START_PIN_DIGEST, START_PIN_DIGEST],
        'not a digest' => ['postgres:16', 'sha256:abc; rm -rf /'],
    ]);

    it('pulls the digest and gives it the tag, with quoted arguments', function () {
        $commands = ImageStartPin::commands(['x' => ['ref' => 'ghcr.io/acme/web:2.0', 'pull' => 'ghcr.io/acme/web@'.START_PIN_DIGEST]], 'DOCKER_CONFIG=/auth ');

        expect($commands[1])->toBe("DOCKER_CONFIG=/auth docker pull 'ghcr.io/acme/web@".START_PIN_DIGEST."' && DOCKER_CONFIG=/auth docker tag 'ghcr.io/acme/web@".START_PIN_DIGEST."' 'ghcr.io/acme/web:2.0'");
    });

    it('keeps only the pins of the images a start runs', function () {
        $pins = ['docker.io/library/nginx:1.27' => ['ref' => 'nginx:1.27', 'pull' => 'p1'], 'docker.io/library/redis:7' => ['ref' => 'redis:7', 'pull' => 'p2']];

        expect(ImageStartPin::for($pins, ['docker.io/library/nginx:1.27']))->toBe(['docker.io/library/nginx:1.27' => ['ref' => 'nginx:1.27', 'pull' => 'p1']]);
    });
});

describe('standalone database start', function () {
    it('pulls the scanned build instead of the tag, and keeps the tag in the compose file', function () {
        $database = create_standalone_postgresql($this->environment->id, $this->destination);
        ImageStartPin::put($database, $database->image, START_PIN_DIGEST);

        StartPostgresql::run($database->fresh(), new Activity);

        $commands = implode("\n", $this->databaseCommands);
        expect($commands)->toContain("docker pull 'docker.io/library/postgres@".START_PIN_DIGEST."' && ")
            ->and($commands)->toContain("docker tag 'docker.io/library/postgres@".START_PIN_DIGEST."' '{$database->image}'")
            ->and($commands)->not->toContain('docker-compose.yml pull')
            ->and(ImageStartPin::take($database))->toBe([]);
    });

    it('pulls the configured image without a pin', function () {
        $database = create_standalone_postgresql($this->environment->id, $this->destination);

        StartPostgresql::run($database->fresh(), new Activity);

        expect(implode("\n", $this->databaseCommands))->toContain('docker-compose.yml pull')->not->toContain('docker tag');
    });
});

describe('service start', function () {
    it('gives the scanned build its tag after the pull of the images', function () {
        $service = Service::factory()->create([
            'environment_id' => $this->environment->id,
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
        ServiceApplication::forceCreate(['uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'web', 'image' => 'nginx:1.27']);
        ImageStartPin::put($service, 'nginx:1.27', START_PIN_DIGEST);

        $command = (string) RemoteProcessCommand::read(StartService::run($service, true));

        $pull = strpos($command, ' pull < /dev/null');
        $tag = strpos($command, "docker tag 'docker.io/library/nginx@".START_PIN_DIGEST."' 'nginx:1.27'");
        expect($pull)->not->toBeFalse()
            ->and($tag)->not->toBeFalse()
            ->and($tag)->toBeGreaterThan($pull)
            ->and(ImageStartPin::take($service))->toBe([]);
    });
});
