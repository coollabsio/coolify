<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    Bus::fake();
    Queue::fake();
    Process::fake(['*' => Process::result(output: '')]);
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
});

it('keeps a top-level volume without options that only a service from extends mounts', function (string $declaration) {
    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => <<<YAML
            services:
              app:
                extends:
                  file: shared.yaml
                  service: app
              web:
                image: nginx:alpine
                volumes:
                  - web-data:/data
            volumes:
              app-data:{$declaration}
              web-data:
            YAML,
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    applicationParser($application);

    $uuid = $application->uuid;
    $compose = Yaml::parse($application->fresh()->docker_compose);
    expect($compose['services']['app']['extends'])->toBe(['file' => 'shared.yaml', 'service' => 'app'])
        ->and($compose['volumes'])->toHaveKey('app-data')
        ->and($compose['volumes']['app-data'])->toBeEmpty()
        ->and($compose['services']['web']['volumes'])->toBe(["{$uuid}_web-data:/data"])
        ->and($compose['volumes']["{$uuid}_web-data"]['name'])->toBe("{$uuid}_web-data");
})->with([
    'no value' => [''],
    'empty map' => [' {}'],
]);
