<?php

use App\Enums\ImageScanGateMode;
use App\Enums\ImageScanOnError;
use App\Models\Application;
use App\Models\Environment;
use App\Models\ImageScanPolicy;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Services\ImageScan\ImageScanPolicyResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    Once::flush();
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

function resolverPolicyFor(Model $scope, array $values): ImageScanPolicy
{
    return ImageScanPolicy::query()->create([
        'scope_type' => $scope->getMorphClass(),
        'scope_id' => $scope->id,
        ...$values,
    ]);
}

function resolverEnableScanning(): void
{
    test()->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
}

function resolverService(): Service
{
    return Service::factory()->create([
        'environment_id' => test()->environment->id,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:alpine\n",
    ]);
}

it('uses the instance defaults when no level sets a value', function () {
    $policy = ImageScanPolicyResolver::for($this->application);

    expect($policy->gateMode)->toBe('off')
        ->and($policy->onScanError)->toBe(ImageScanOnError::Deploy)
        ->and($policy->sources)->toBe([
            'gate_mode' => 'instance',
            'on_scan_error' => 'instance',
        ])
        ->and($policy->scanServer->is($this->server))->toBeTrue();
});

it('reads the instance row from the database', function () {
    ImageScanPolicy::query()->whereNull('scope_type')->update([
        'gate_mode' => 'strict',
        'on_scan_error' => 'block',
    ]);

    $policy = ImageScanPolicyResolver::for($this->application);

    expect($policy->gateMode)->toBe('strict')
        ->and($policy->onScanError)->toBe(ImageScanOnError::Block);
});

it('falls back to the code defaults when the instance row is missing', function () {
    ImageScanPolicy::query()->whereNull('scope_type')->delete();

    $policy = ImageScanPolicyResolver::for($this->application);

    expect($policy->gateMode)->toBe('off')
        ->and($policy->onScanError)->toBe(ImageScanOnError::Deploy)
        ->and($policy->sources['gate_mode'])->toBe('instance');
});

it('reads the lowest id when the instance row exists twice', function () {
    ImageScanPolicy::query()->whereNull('scope_type')->update(['gate_mode' => 'on']);
    ImageScanPolicy::query()->create(['gate_mode' => 'strict', 'on_scan_error' => 'block']);

    expect(ImageScanPolicyResolver::for($this->application)->gateMode)->toBe('on');
});

it('lets the team override the instance', function () {
    resolverPolicyFor($this->team, ['gate_mode' => ImageScanGateMode::On]);

    $policy = ImageScanPolicyResolver::for($this->application);

    expect($policy->gateMode)->toBe('on')
        ->and($policy->sources['gate_mode'])->toBe('team')
        ->and($policy->sources['on_scan_error'])->toBe('instance');
});

it('lets the server override the team and the app override the server', function () {
    resolverPolicyFor($this->team, ['gate_mode' => ImageScanGateMode::Strict, 'on_scan_error' => ImageScanOnError::Block]);
    resolverPolicyFor($this->server, ['gate_mode' => ImageScanGateMode::Off]);
    resolverPolicyFor($this->application, ['gate_mode' => ImageScanGateMode::On]);

    $policy = ImageScanPolicyResolver::for($this->application);

    expect($policy->gateMode)->toBe('on')
        ->and($policy->onScanError)->toBe(ImageScanOnError::Block)
        ->and($policy->sources)->toBe([
            'gate_mode' => 'app',
            'on_scan_error' => 'team',
        ]);
});

it('turns the gate off at the app level even when the server sets strict', function () {
    resolverPolicyFor($this->server, ['gate_mode' => ImageScanGateMode::Strict]);
    resolverPolicyFor($this->application, ['gate_mode' => ImageScanGateMode::Off]);

    $policy = ImageScanPolicyResolver::for($this->application);

    expect($policy->gateMode)->toBe('off')
        ->and($policy->sources['gate_mode'])->toBe('app');
});

it('inherits the server value when the app value is null', function () {
    resolverPolicyFor($this->server, ['gate_mode' => ImageScanGateMode::Strict]);
    resolverPolicyFor($this->application, ['gate_mode' => null, 'on_scan_error' => ImageScanOnError::Block]);

    $policy = ImageScanPolicyResolver::for($this->application);

    expect($policy->gateMode)->toBe('strict')
        ->and($policy->sources['gate_mode'])->toBe('server')
        ->and($policy->sources['on_scan_error'])->toBe('app');
});

it('resolves a service without the app level and never turns the gate on', function () {
    $service = resolverService();
    $serviceApplication = ServiceApplication::create(['name' => 'app', 'service_id' => $service->id]);
    resolverPolicyFor($this->server, ['gate_mode' => ImageScanGateMode::Strict, 'on_scan_error' => ImageScanOnError::Block]);

    foreach ([$service, $serviceApplication] as $resource) {
        $policy = ImageScanPolicyResolver::for($resource);

        expect($policy->gateMode)->toBe('off')
            ->and($policy->onScanError)->toBe(ImageScanOnError::Block)
            ->and($policy->sources['on_scan_error'])->toBe('server')
            ->and($policy->scanServer->is($this->server))->toBeTrue();
    }
});

it('does not read app rows for a database', function () {
    $database = StandalonePostgresql::forceCreate([
        'name' => 'pg',
        'postgres_password' => 'secret',
        'image' => 'postgres:16',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    resolverPolicyFor($this->server, ['gate_mode' => ImageScanGateMode::On]);
    ImageScanPolicy::query()->create([
        'scope_type' => $database->getMorphClass(),
        'scope_id' => $database->id,
        'on_scan_error' => ImageScanOnError::Block,
    ]);

    $policy = ImageScanPolicyResolver::for($database);

    expect($policy->gateMode)->toBe('off')
        ->and($policy->onScanError)->toBe(ImageScanOnError::Deploy)
        ->and($policy->scanServer->is($this->server))->toBeTrue();
});

it('uses the team of the resource and not the session team', function () {
    $otherTeam = Team::factory()->create();
    resolverPolicyFor($this->team, ['gate_mode' => ImageScanGateMode::On]);
    resolverPolicyFor($otherTeam, ['gate_mode' => ImageScanGateMode::Strict]);
    session(['currentTeam' => $otherTeam]);

    expect(ImageScanPolicyResolver::for($this->application)->gateMode)->toBe('on');
});

it('scans on the given server but resolves the rules through the main destination server', function () {
    $buildServer = Server::factory()->create(['team_id' => $this->team->id]);
    resolverPolicyFor($this->server, ['gate_mode' => ImageScanGateMode::Strict]);
    resolverPolicyFor($buildServer, ['gate_mode' => ImageScanGateMode::Off]);

    $policy = ImageScanPolicyResolver::for($this->application, $buildServer);

    expect($policy->gateMode)->toBe('strict')
        ->and($policy->sources['gate_mode'])->toBe('server')
        ->and($policy->scanServer->is($buildServer))->toBeTrue();
});

it('lists the inherited value and its level for each field', function () {
    resolverPolicyFor($this->server, ['gate_mode' => ImageScanGateMode::On]);

    expect(ImageScanPolicyResolver::inheritedFor($this->application))->toBe([
        'gate_mode' => ['value' => ImageScanGateMode::On, 'source' => 'server'],
        'on_scan_error' => ['value' => ImageScanOnError::Deploy, 'source' => 'instance'],
    ]);
});

it('keeps scanning off until the server opts in', function () {
    expect(ImageScanPolicyResolver::for($this->application)->scanEnabled)->toBeFalse();
});

it('enables scanning when the instance, the server, and the app allow it', function () {
    resolverEnableScanning();

    expect(ImageScanPolicyResolver::for($this->application)->scanEnabled)->toBeTrue();
});

it('disables scanning when the instance does not allow it', function () {
    resolverEnableScanning();
    InstanceSettings::findOrFail(0)->forceFill(['is_image_scanning_allowed' => false])->saveQuietly();
    Once::flush();

    expect(ImageScanPolicyResolver::for($this->application)->scanEnabled)->toBeFalse();
});

it('disables scanning when the app turned its scan off', function () {
    resolverEnableScanning();
    $this->application->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();

    expect(ImageScanPolicyResolver::for($this->application)->scanEnabled)->toBeFalse();
});

it('checks the server opt-in of the given server', function () {
    resolverEnableScanning();
    $buildServer = Server::factory()->create(['team_id' => $this->team->id]);

    expect(ImageScanPolicyResolver::for($this->application, $buildServer)->scanEnabled)->toBeFalse();
});

it('does not apply the app scan switch to services', function () {
    resolverEnableScanning();

    expect(ImageScanPolicyResolver::for(resolverService())->scanEnabled)->toBeTrue();
});

it('returns the instance row, an existing row, or a new unsaved row to edit', function () {
    $instance = ImageScanPolicyResolver::forScope(null);
    $new = ImageScanPolicyResolver::forScope($this->server);
    $existing = resolverPolicyFor($this->team, ['gate_mode' => ImageScanGateMode::On]);

    expect($instance->exists)->toBeTrue()
        ->and($instance->scope_type)->toBeNull()
        ->and($new->exists)->toBeFalse()
        ->and($new->scope_type)->toBe($this->server->getMorphClass())
        ->and($new->scope_id)->toBe($this->server->id)
        ->and(ImageScanPolicyResolver::forScope($this->team)->is($existing))->toBeTrue()
        ->and(ImageScanPolicy::query()->count())->toBe(2);
});
