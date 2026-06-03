<?php

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create([
            'id' => 0,
            'is_registration_enabled' => true,
        ]);
    });

    $this->user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);

    $this->team = Team::factory()->create([
        'show_boarding' => false,
    ]);
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::create([
        'team_id' => $this->team->id,
        'name' => 'Test Key',
        'description' => 'Test SSH key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => ProxyStatus::EXITED->value,
        ],
    ]);

    $this->destination = StandaloneDocker::query()
        ->where('server_id', $this->server->id)
        ->firstOrFail();

    $this->project = Project::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
    ]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'running',
    ]);
});

it('renders responsive checkbox classes on the application configuration page', function () {
    $response = $this->get(route('project.application.configuration', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]));

    $response->assertSuccessful();
    $response->assertSee('Use a Build Server?');
    $response->assertSee('form-control flex max-w-full flex-row items-center gap-4 py-1 pr-2', false);
    $response->assertSee('label flex w-full max-w-full min-w-0 items-center gap-4 px-0', false);
    $response->assertSee('flex min-w-0 grow gap-2 break-words', false);
    $response->assertSee('shrink-0', false);
    $response->assertSee('pt-2 w-full sm:w-96', false);

    expect($response->getContent())->not->toContain('min-w-fit');
});

it('renders responsive checkbox classes on the server page', function () {
    $response = $this->get(route('server.show', [
        'server_uuid' => $this->server->uuid,
    ]));

    $response->assertSuccessful();
    $response->assertSee('Use it as a build server?');
    $response->assertSee('form-control flex max-w-full flex-row items-center gap-4 py-1 pr-2', false);
    $response->assertSee('label flex w-full max-w-full min-w-0 items-center gap-4 px-0', false);
    $response->assertSee('flex min-w-0 grow gap-2 break-words', false);
    $response->assertSee('shrink-0', false);
    $response->assertSee('w-full sm:w-96', false);

    expect($response->getContent())->not->toContain('min-w-fit');
});
