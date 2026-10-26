<?php

use App\Enums\ImageScanAlertLevel;
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

    expect($policy->alertLevel)->toBe(ImageScanAlertLevel::Critical)
        ->and($policy->gateEnabled)->toBeFalse()
        ->and($policy->onScanError)->toBe(ImageScanOnError::Deploy)
        ->and($policy->sources)->toBe([
            'alert_level' => 'instance',
            'gate_enabled' => 'instance',
            'on_scan_error' => 'instance',
        ])
        ->and($policy->scanServer->is($this->server))->toBeTrue();
});

it('reads the instance row from the database', function () {
    ImageScanPolicy::query()->whereNull('scope_type')->update([
        'alert_level' => 'medium',
        'gate_enabled' => true,
        'on_scan_error' => 'block',
    ]);

    $policy = ImageScanPolicyResolver::for($this->application);

    expect($policy->alertLevel)->toBe(ImageScanAlertLevel::Medium)
        ->and($policy->gateEnabled)->toBeTrue()
        ->and($policy->onScanError)->toBe(ImageScanOnError::Block);
});

it('falls back to the code defaults when the instance row is missing', function () {
    ImageScanPolicy::query()->whereNull('scope_type')->delete();

    $policy = ImageScanPolicyResolver::for($this->application);

    expect($policy->alertLevel)->toBe(ImageScanAlertLevel::Critical)
        ->and($policy->gateEnabled)->toBeFalse()
        ->and($policy->onScanError)->toBe(ImageScanOnError::Deploy)
        ->and($policy->sources['alert_level'])->toBe('instance');
});

it('reads the lowest id when the instance row exists twice', function () {
    ImageScanPolicy::query()->whereNull('scope_type')->update(['alert_level' => 'high']);
    ImageScanPolicy::query()->create(['alert_level' => 'medium', 'gate_enabled' => true, 'on_scan_error' => 'block']);

    expect(ImageScanPolicyResolver::for($this->application)->alertLevel)->toBe(ImageScanAlertLevel::High);
});

it('lets the team override the instance', function () {
    resolverPolicyFor($this->team, ['alert_level' => ImageScanAlertLevel::High]);

    $policy = ImageScanPolicyResolver::for($this->application);

    expect($policy->alertLevel)->toBe(ImageScanAlertLevel::High)
        ->and($policy->sources['alert_level'])->toBe('team')
        ->and($policy->sources['gate_enabled'])->toBe('instance');
});

it('lets the server override the team and the app override the server', function () {
    resolverPolicyFor($this->team, ['alert_level' => ImageScanAlertLevel::High, 'on_scan_error' => ImageScanOnError::Block]);
    resolverPolicyFor($this->server, ['alert_level' => ImageScanAlertLevel::Medium, 'gate_enabled' => true]);
    resolverPolicyFor($this->application, ['alert_level' => ImageScanAlertLevel::Critical]);

    $policy = ImageScanPolicyResolver::for($this->application);

    expect($policy->alertLevel)->toBe(ImageScanAlertLevel::Critical)
        ->and($policy->gateEnabled)->toBeTrue()
        ->and($policy->onScanError)->toBe(ImageScanOnError::Block)
        ->and($policy->sources)->toBe([
            'alert_level' => 'app',
            'gate_enabled' => 'server',
            'on_scan_error' => 'team',
        ]);
});

it('turns the gate off at the app level even when the server turns it on', function () {
    resolverPolicyFor($this->server, ['gate_enabled' => true]);
    resolverPolicyFor($this->application, ['gate_enabled' => false]);

    $policy = ImageScanPolicyResolver::for($this->application);

    expect($policy->gateEnabled)->toBeFalse()
        ->and($policy->sources['gate_enabled'])->toBe('app');
});

it('inherits the server value when the app value is null', function () {
    resolverPolicyFor($this->server, ['gate_enabled' => true]);
    resolverPolicyFor($this->application, ['gate_enabled' => null, 'alert_level' => ImageScanAlertLevel::High]);

    $policy = ImageScanPolicyResolver::for($this->application);

    expect($policy->gateEnabled)->toBeTrue()
        ->and($policy->sources['gate_enabled'])->toBe('server')
        ->and($policy->sources['alert_level'])->toBe('app');
});

it('resolves a service without the app level and never enables the gate', function () {
    $service = resolverService();
    $serviceApplication = ServiceApplication::create(['name' => 'app', 'service_id' => $service->id]);
    resolverPolicyFor($this->server, ['gate_enabled' => true, 'alert_level' => ImageScanAlertLevel::High]);

    foreach ([$service, $serviceApplication] as $resource) {
        $policy = ImageScanPolicyResolver::for($resource);

        expect($policy->gateEnabled)->toBeFalse()
            ->and($policy->alertLevel)->toBe(ImageScanAlertLevel::High)
            ->and($policy->sources['alert_level'])->toBe('server')
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
    resolverPolicyFor($this->server, ['gate_enabled' => true]);
    ImageScanPolicy::query()->create([
        'scope_type' => $database->getMorphClass(),
        'scope_id' => $database->id,
        'alert_level' => ImageScanAlertLevel::Medium,
    ]);

    $policy = ImageScanPolicyResolver::for($database);

    expect($policy->gateEnabled)->toBeFalse()
        ->and($policy->alertLevel)->toBe(ImageScanAlertLevel::Critical)
        ->and($policy->scanServer->is($this->server))->toBeTrue();
});

it('uses the team of the resource and not the session team', function () {
    $otherTeam = Team::factory()->create();
    resolverPolicyFor($this->team, ['alert_level' => ImageScanAlertLevel::High]);
    resolverPolicyFor($otherTeam, ['alert_level' => ImageScanAlertLevel::Medium]);
    session(['currentTeam' => $otherTeam]);

    expect(ImageScanPolicyResolver::for($this->application)->alertLevel)->toBe(ImageScanAlertLevel::High);
});

it('uses the given server instead of the destination server', function () {
    $buildServer = Server::factory()->create(['team_id' => $this->team->id]);
    resolverPolicyFor($this->server, ['alert_level' => ImageScanAlertLevel::High]);
    resolverPolicyFor($buildServer, ['alert_level' => ImageScanAlertLevel::Medium]);

    $policy = ImageScanPolicyResolver::for($this->application, $buildServer);

    expect($policy->alertLevel)->toBe(ImageScanAlertLevel::Medium)
        ->and($policy->scanServer->is($buildServer))->toBeTrue();
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
    $existing = resolverPolicyFor($this->team, ['alert_level' => ImageScanAlertLevel::High]);

    expect($instance->exists)->toBeTrue()
        ->and($instance->scope_type)->toBeNull()
        ->and($new->exists)->toBeFalse()
        ->and($new->scope_type)->toBe($this->server->getMorphClass())
        ->and($new->scope_id)->toBe($this->server->id)
        ->and(ImageScanPolicyResolver::forScope($this->team)->is($existing))->toBeTrue()
        ->and(ImageScanPolicy::query()->count())->toBe(2);
});
