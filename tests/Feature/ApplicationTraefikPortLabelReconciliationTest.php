<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);

    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('traefik-port-label-test', ['*'])->plainTextToken;
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => 'https://app.example.com',
        'ports_exposes' => '80',
    ]);
    $this->application->settings->update([
        'is_container_label_readonly_enabled' => true,
    ]);
});

function persistGeneratedApplicationLabels(Application $application): string
{
    $application->refresh();
    $labels = implode("\n", generateLabelsApplication($application));
    $application->custom_labels = base64_encode($labels);
    $application->save();

    return $labels;
}

function decodedApplicationLabels(Application $application): string
{
    $stored = $application->getRawOriginal('custom_labels');

    if (! $stored) {
        return '';
    }

    if (base64_encode(base64_decode($stored, true)) === $stored) {
        return (string) base64_decode($stored);
    }

    return (string) $stored;
}

test('changing ports_exposes reconciles stale traefik load balancer port labels', function () {
    $initialLabels = persistGeneratedApplicationLabels($this->application);

    expect($initialLabels)->toContain('loadbalancer.server.port=80')
        ->and($initialLabels)->not->toContain('loadbalancer.server.port=9000');

    $this->application->update(['ports_exposes' => '9000']);
    $this->application->refresh();

    $reconciled = $this->application->parseContainerLabels();

    expect($this->application->ports_exposes)->toBe('9000')
        ->and($reconciled)->toContain('loadbalancer.server.port=9000')
        ->and($reconciled)->not->toContain('loadbalancer.server.port=80')
        ->and($reconciled)->not->toContain('{{upstreams 80}}');
});

test('updating ports_exposes via the api regenerates managed load balancer port labels', function () {
    persistGeneratedApplicationLabels($this->application);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
        'Content-Type' => 'application/json',
    ])->patchJson("/api/v1/applications/{$this->application->uuid}", [
        'ports_exposes' => '9000',
    ])->assertOk();

    $application = $this->application->fresh();
    $labels = decodedApplicationLabels($application);

    expect($application->ports_exposes)->toBe('9000')
        ->and($labels)->toContain('loadbalancer.server.port=9000')
        ->and($labels)->not->toContain('loadbalancer.server.port=80');
});

test('changing ports_exposes preserves user-managed labels when readonly is disabled', function () {
    $this->application->settings->update([
        'is_container_label_readonly_enabled' => false,
    ]);
    $this->application->update([
        'custom_labels' => base64_encode("sentinel-label=true\ntraefik.http.services.http-0-{$this->application->uuid}.loadbalancer.server.port=80"),
    ]);

    $this->application->update(['ports_exposes' => '9000']);
    $this->application->refresh();

    $labels = $this->application->parseContainerLabels();

    expect($labels)->toContain('sentinel-label=true')
        ->and($labels)->toContain('loadbalancer.server.port=80')
        ->and($labels)->not->toContain('loadbalancer.server.port=9000');
});
