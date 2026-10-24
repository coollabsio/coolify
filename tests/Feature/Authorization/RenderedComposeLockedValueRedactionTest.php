<?php

use App\Livewire\Project\Application\General;
use App\Livewire\Project\Service\EditCompose;
use App\Livewire\Project\Service\StackForm;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const LOCKED_COMPOSE_RAW = <<<'YAML'
services:
  web:
    image: nginx:alpine
    environment:
      LOCKED_TOKEN: ""
      PLAIN_TOKEN: ""
      TEAM_SECRET: '{{team.TEAM_SECRET}}'
YAML;

beforeEach(function () {
    Queue::fake();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => 'owner']);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    SharedEnvironmentVariable::create([
        'type' => 'team',
        'key' => 'TEAM_SECRET',
        'value' => 'team-shared-locked-secret',
        'team_id' => $this->team->id,
        'is_shown_once' => true,
    ]);

    $this->service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => LOCKED_COMPOSE_RAW,
    ]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => LOCKED_COMPOSE_RAW,
    ]);

    foreach ([$this->service, $this->application] as $resource) {
        $resource->environment_variables()->create(['key' => 'LOCKED_TOKEN', 'value' => 'env-locked-secret-value', 'is_shown_once' => true]);
        $resource->environment_variables()->create(['key' => 'PLAIN_TOKEN', 'value' => 'plain-visible-value']);
        $resource->parse();
        $resource->refresh();
    }
});

function lockedComposeApiToken(): string
{
    $plainTextToken = Str::random(40);
    $token = test()->owner->tokens()->create([
        'name' => 'test-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['read', 'read:sensitive'],
        'team_id' => test()->team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

function expectLockedValuesRedacted(?string $text): void
{
    expect($text)->not->toContain('env-locked-secret-value')
        ->not->toContain('team-shared-locked-secret')
        ->toContain('<locked>')
        ->toContain('plain-visible-value');
}

function expectStoredComposeKeepsRealValues(Application|Service $resource): void
{
    expect($resource->fresh()->docker_compose)
        ->not->toContain('<locked>')
        ->toContain('env-locked-secret-value')
        ->toContain('team-shared-locked-secret');
}

it('writes the locked values into the generated compose that deployments use', function (string $resource) {
    expectStoredComposeKeepsRealValues($this->{$resource});
})->with(['service', 'application']);

it('hides locked values in the service compose that an owner sees', function (string $component) {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $livewire = Livewire::test($component, ['service' => $this->service, 'serviceId' => $this->service->id]);

    expectLockedValuesRedacted($livewire->get('dockerCompose'));
    expect(json_encode($livewire->snapshot).$livewire->html())
        ->not->toContain('env-locked-secret-value')
        ->not->toContain('team-shared-locked-secret');
})->with(['stack form' => [StackForm::class], 'compose editor' => [EditCompose::class]]);

it('hides locked values in the application compose that an owner sees', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $livewire = Livewire::test(General::class, ['application' => $this->application]);

    expectLockedValuesRedacted($livewire->get('dockerCompose'));
    expect(json_encode($livewire->snapshot).$livewire->html())
        ->not->toContain('env-locked-secret-value')
        ->not->toContain('team-shared-locked-secret');
});

it('keeps the real generated compose when an owner saves the service stack form', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(StackForm::class, ['service' => $this->service])
        ->call('submit')
        ->assertHasNoErrors();

    expectStoredComposeKeepsRealValues($this->service);
});

it('keeps the real generated compose when an owner saves the application form', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(General::class, ['application' => $this->application])
        ->call('submit')
        ->assertHasNoErrors();

    expectStoredComposeKeepsRealValues($this->application);
});

it('hides locked values in the generated compose returned by the API', function (string $resource) {
    $path = $resource === 'service'
        ? '/api/v1/services/'.$this->service->uuid
        : '/api/v1/applications/'.$this->application->uuid;

    $response = $this->withToken(lockedComposeApiToken())->getJson($path)->assertOk();

    expectLockedValuesRedacted($response->json('docker_compose'));
    expect($response->json('docker_compose_raw'))->toBe(LOCKED_COMPOSE_RAW);
})->with(['service', 'application']);
