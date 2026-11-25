<?php

use App\Enums\ProxyTypes;
use App\Livewire\Project\Service\Index;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  web:\n    build:\n      dockerfile_inline: |\n        FROM nginx:alpine\n  cache:\n    image: redis:7\n",
    ]);
    $this->builtApplication = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'web',
        'service_id' => $this->service->id,
        'image' => '',
    ]);
    $this->imageApplication = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'cache',
        'service_id' => $this->service->id,
        'image' => 'redis:7',
    ]);
});

it('saves the domain of a service that is built without an image', function () {
    Livewire::test(Index::class, ['serviceApplication' => $this->builtApplication->fresh()])
        ->set('fqdn', 'https://web.example.com')
        ->call('submitApplication')
        ->assertHasNoErrors()
        ->assertNotDispatched('error');

    expect($this->builtApplication->fresh()->fqdn)->toBe('https://web.example.com');

    $compose = Yaml::parse($this->service->fresh()->docker_compose_raw);
    expect($compose['services']['web'])->not->toHaveKey('image')
        ->and($compose['services']['web']['build']['dockerfile_inline'])->toContain('FROM nginx:alpine');
});

it('still requires the image of a service that has one', function () {
    Livewire::test(Index::class, ['serviceApplication' => $this->imageApplication->fresh()])
        ->set('image', '')
        ->call('submitApplication')
        ->assertDispatched('error');

    expect($this->imageApplication->fresh()->image)->toBe('redis:7');
});
