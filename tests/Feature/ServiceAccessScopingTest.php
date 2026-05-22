<?php

use App\Livewire\Project\Service\Heading as ServiceHeading;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.driver' => 'file',
        'cache.default' => 'array',
        'queue.default' => 'sync',
        'session.driver' => 'array',
    ]);

    $this->withoutVite();

    InstanceSettings::forceCreate(['id' => 0]);

    $this->userA = User::factory()->create();
    $this->teamA = Team::factory()->create();
    $this->userA->teams()->attach($this->teamA, ['role' => 'owner']);

    $this->serverA = Server::factory()->create(['team_id' => $this->teamA->id]);
    $this->destinationA = StandaloneDocker::factory()->create(['server_id' => $this->serverA->id, 'network' => 'net-a-'.fake()->uuid()]);
    $this->projectA = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->environmentA = Environment::factory()->create(['project_id' => $this->projectA->id]);
    $this->ownService = Service::factory()->create([
        'environment_id' => $this->environmentA->id,
        'destination_id' => $this->destinationA->id,
        'destination_type' => $this->destinationA->getMorphClass(),
    ]);

    $this->userB = User::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->userB->teams()->attach($this->teamB, ['role' => 'owner']);

    $this->serverB = Server::factory()->create(['team_id' => $this->teamB->id]);
    $this->destinationB = StandaloneDocker::factory()->create(['server_id' => $this->serverB->id, 'network' => 'net-b-'.fake()->uuid()]);
    $this->projectB = Project::factory()->create(['team_id' => $this->teamB->id]);
    $this->environmentB = Environment::factory()->create(['project_id' => $this->projectB->id]);
    $this->externalService = Service::factory()->create([
        'environment_id' => $this->environmentB->id,
        'destination_id' => $this->destinationB->id,
        'destination_type' => $this->destinationB->getMorphClass(),
    ]);
    $this->externalServiceApplication = ServiceApplication::create([
        'name' => 'external-app',
        'service_id' => $this->externalService->id,
        'image' => 'nginx:alpine',
        'fqdn' => 'https://external.example.test',
    ]);
    $this->externalServiceDatabase = ServiceDatabase::create([
        'name' => 'external-db',
        'service_id' => $this->externalService->id,
        'image' => 'postgres:16-alpine',
    ]);

    $this->actingAs($this->userA);
    session(['currentTeam' => $this->teamA]);
});

test('service child routes require matching service application hierarchy', function (string $routeName) {
    $response = $this->get(route($routeName, [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->externalService->uuid,
        'stack_service_uuid' => $this->externalServiceApplication->uuid,
    ]));

    $response->assertRedirect(route('dashboard'));
})->with([
    'index' => 'project.service.index',
    'advanced' => 'project.service.index.advanced',
]);

test('service database routes require matching service database hierarchy', function (string $routeName) {
    $response = $this->get(route($routeName, [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->externalService->uuid,
        'stack_service_uuid' => $this->externalServiceDatabase->uuid,
    ]));

    $response->assertRedirect(route('dashboard'));
})->with([
    'index' => 'project.service.index',
    'advanced' => 'project.service.index.advanced',
    'backups' => 'project.service.database.backups',
    'import' => 'project.service.database.import',
]);

test('service policies deny resources outside the active team', function () {
    expect(Gate::allows('view', $this->externalService))->toBeFalse()
        ->and(Gate::allows('update', $this->externalService))->toBeFalse()
        ->and(Gate::allows('delete', $this->externalService))->toBeFalse()
        ->and(Gate::allows('deploy', $this->externalService))->toBeFalse()
        ->and(Gate::allows('stop', $this->externalService))->toBeFalse()
        ->and(Gate::allows('update', $this->externalServiceApplication))->toBeFalse()
        ->and(Gate::allows('delete', $this->externalServiceApplication))->toBeFalse()
        ->and(Gate::allows('update', $this->externalServiceDatabase))->toBeFalse()
        ->and(Gate::allows('delete', $this->externalServiceDatabase))->toBeFalse()
        ->and(Gate::allows('manageBackups', $this->externalServiceDatabase))->toBeFalse();
});

test('service policies allow current-team service and child resources', function () {
    $ownServiceApplication = ServiceApplication::create([
        'name' => 'own-app',
        'service_id' => $this->ownService->id,
        'image' => 'nginx:alpine',
    ]);
    $ownServiceDatabase = ServiceDatabase::create([
        'name' => 'own-db',
        'service_id' => $this->ownService->id,
        'image' => 'postgres:16-alpine',
    ]);

    expect(Gate::allows('view', $this->ownService))->toBeTrue()
        ->and(Gate::allows('update', $this->ownService))->toBeTrue()
        ->and(Gate::allows('delete', $this->ownService))->toBeTrue()
        ->and(Gate::allows('deploy', $this->ownService))->toBeTrue()
        ->and(Gate::allows('stop', $this->ownService))->toBeTrue()
        ->and(Gate::allows('update', $ownServiceApplication))->toBeTrue()
        ->and(Gate::allows('delete', $ownServiceApplication))->toBeTrue()
        ->and(Gate::allows('update', $ownServiceDatabase))->toBeTrue()
        ->and(Gate::allows('delete', $ownServiceDatabase))->toBeTrue()
        ->and(Gate::allows('manageBackups', $ownServiceDatabase))->toBeTrue();
});

test('service heading requires active team service', function () {
    Livewire::test(ServiceHeading::class, ['service' => $this->externalService])
        ->assertForbidden();
});

test('service heading status actions require active team service', function (string $method) {
    $component = new ServiceHeading;
    $component->service = $this->externalService;

    expect(fn () => $component->{$method}())->toThrow(AuthorizationException::class);
})->with([
    'checkStatus',
    'manualCheckStatus',
    'serviceChecked',
    'checkDeployments',
]);
