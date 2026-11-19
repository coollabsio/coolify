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
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
    ]);
    $this->serviceApplication = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'web',
        'service_id' => $service->id,
        'image' => 'nginx:alpine',
    ]);
});

it('stores the certificate resolver for each mode', function (string $mode, ?string $name, ?string $expected) {
    Livewire::test(Index::class, ['serviceApplication' => $this->serviceApplication->fresh()])
        ->set('certificateResolverMode', $mode)
        ->set('certificateResolverName', $name)
        ->call('submitApplication')
        ->assertHasNoErrors()
        ->assertNotDispatched('error');

    expect($this->serviceApplication->fresh()->traefik_certificate_resolver)->toBe($expected);

    Livewire::test(Index::class, ['serviceApplication' => $this->serviceApplication->fresh()])
        ->assertSet('certificateResolverMode', $mode)
        ->assertSet('certificateResolverName', $mode === 'custom' ? $name : null);
})->with([
    'server setting' => ['server', null, null],
    'no resolver' => ['none', null, 'none'],
    'custom resolver' => ['custom', 'cloudflare', 'cloudflare'],
]);

it('switches back to the server setting', function () {
    $this->serviceApplication->update(['traefik_certificate_resolver' => 'cloudflare']);

    Livewire::test(Index::class, ['serviceApplication' => $this->serviceApplication->fresh()])
        ->assertSet('certificateResolverMode', 'custom')
        ->set('certificateResolverMode', 'server')
        ->call('submitApplication')
        ->assertNotDispatched('error');

    expect($this->serviceApplication->fresh()->traefik_certificate_resolver)->toBeNull();
});

it('rejects an invalid custom resolver name', function (?string $name) {
    $this->serviceApplication->update(['traefik_certificate_resolver' => 'letsencrypt']);

    Livewire::test(Index::class, ['serviceApplication' => $this->serviceApplication->fresh()])
        ->set('certificateResolverMode', 'custom')
        ->set('certificateResolverName', $name)
        ->call('submitApplication')
        ->assertDispatched('error');

    expect($this->serviceApplication->fresh()->traefik_certificate_resolver)->toBe('letsencrypt');
})->with([
    'invalid characters' => ['a.b=c'],
    'reserved none' => ['none'],
    'empty' => [null],
]);

it('rejects an unknown certificate resolver mode', function () {
    Livewire::test(Index::class, ['serviceApplication' => $this->serviceApplication->fresh()])
        ->set('certificateResolverMode', 'letsencrypt')
        ->call('submitApplication')
        ->assertDispatched('error');

    expect($this->serviceApplication->fresh()->traefik_certificate_resolver)->toBeNull();
});

it('does not let a team member change the certificate resolver', function () {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

    Livewire::test(Index::class, ['serviceApplication' => $this->serviceApplication->fresh()])
        ->set('certificateResolverMode', 'none')
        ->call('submitApplication')
        ->assertDispatched('error');

    expect($this->serviceApplication->fresh()->traefik_certificate_resolver)->toBeNull();
});
