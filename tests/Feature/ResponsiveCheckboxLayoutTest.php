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

/**
 * Checkbox rows must let long labels wrap next to a fixed-size toggle on narrow screens.
 */
function assertResponsiveCheckboxMarkup($response): void
{
    $response->assertSee('form-control group flex min-h-9 max-w-full', false);
    $response->assertSee('label flex w-full max-w-full min-w-0 items-center', false);
    $response->assertSee('flex min-w-0 grow items-center gap-1.5 break-words', false);
    $response->assertSee('relative flex size-[18px] shrink-0', false);

    expect($response->getContent())->not->toContain('min-w-fit');
}

it('renders responsive checkbox classes on the application configuration page', function () {
    $response = $this->get(route('project.application.configuration', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]));

    $response->assertSuccessful();
    $response->assertSee('Builder selection');
    $response->assertSee('application-mobile-actions');
    $response->assertSee('application-mobile-stop-trigger');
    $response->assertSee('application-mobile-restart-trigger');
    $response->assertSee('wire:click="deploy"', false);
    $response->assertSee('wire:click="force_deploy_without_cache"', false);
    $response->assertDontSee('Confirm Application Deployment?');
    $response->assertSee('Confirm Application Restart?');
    $response->assertDontSee('Confirm Application Force Deployment?');
    $response->assertSee(route('project.application.deployment.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]));
    $response->assertSee(route('project.application.environment-variables', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]));

    assertResponsiveCheckboxMarkup($response);
});

it('renders responsive checkbox classes on the server page', function () {
    $response = $this->get(route('server.show', [
        'server_uuid' => $this->server->uuid,
    ]));

    $response->assertSuccessful();

    assertResponsiveCheckboxMarkup($response);
});
