<?php

use App\Livewire\Project\Service\Domains;
use App\Livewire\Server\DockerImages;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->withoutVite();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0, 'is_dns_validation_enabled' => false]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id, 'ip' => '203.0.113.10']);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
});

it('rejects a client update of the service domain rows', function () {
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
    ));
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
    $service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $environment->id,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
    ]);
    ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'service_id' => $service->id,
        'name' => 'web',
        'image' => 'nginx:alpine',
        'fqdn' => 'https://web.example.com',
    ]);

    Livewire::test(Domains::class, ['service' => $service->fresh(['applications', 'server'])])
        ->assertSet('domainRows', fn (array $rows): bool => collect($rows)->pluck('url')->all() === ['https://web.example.com'])
        ->set('domainRows', [['url' => 'https://attacker.example.com']]);
})->throws(CannotUpdateLockedPropertyException::class);

it('rejects a client update of the docker image list', function () {
    $imageId = 'sha256:'.str_repeat('a', 64);
    Process::fake([
        '*docker image ls*' => Process::result(output: json_encode(['Repository' => 'nginx', 'Tag' => '1.27', 'ID' => $imageId, 'Size' => '52.5MB', 'CreatedSince' => '3 weeks ago'])),
        '*docker ps -a*' => Process::result(output: "{$imageId}#/web\n"),
        '*docker system df*' => Process::result(output: ''),
        '*docker image rm*' => Process::result(output: 'Deleted'),
    ]);

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->assertSet('images.0.containers', ['web'])
        ->set('images.0.containers', []);
})->throws(CannotUpdateLockedPropertyException::class);
