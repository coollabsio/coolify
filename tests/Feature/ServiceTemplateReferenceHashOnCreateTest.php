<?php

use App\Livewire\Project\Resource\Create;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\TemplateUpdateChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0, 'is_api_enabled' => true]));
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    Cache::flush();
    Cache::forever(service_templates_cache_key(), [
        'fetched_at' => now()->toIso8601String(),
        'json' => json_encode(['demo' => ['compose' => base64_encode("services:\n  app:\n    image: nginx:2\n")]]),
    ]);
});

it('creates a one-click service from the UI as up to date with its template', function () {
    $this->actingAs($this->user);
    Event::listen(RouteMatched::class, function (RouteMatched $event) {
        $event->route->setParameter('project_uuid', $this->project->uuid);
        $event->route->setParameter('environment_uuid', $this->environment->uuid);
    });

    Livewire::withQueryParams(['type' => 'one-click-service-demo', 'destination' => $this->destination->uuid])
        ->test(Create::class)
        ->call('createResource')
        ->assertRedirect();

    $service = Service::where('service_type', 'demo')->firstOrFail();
    expect($service->template_reference_hash)->toBe(TemplateUpdateChecker::currentHash('demo'))
        ->and(TemplateUpdateChecker::updateAvailable($service))->toBeFalse();
});

it('creates a one-click service from the API as up to date with its template', function () {
    $token = $this->user->createToken('test-token', ['*'])->plainTextToken;

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/services', [
        'type' => 'demo',
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'server_uuid' => $this->server->uuid,
    ]);

    $response->assertCreated();
    $service = Service::whereUuid($response->json('uuid'))->firstOrFail();
    expect($service->template_reference_hash)->toBe(TemplateUpdateChecker::currentHash('demo'))
        ->and(TemplateUpdateChecker::updateAvailable($service))->toBeFalse();
});
