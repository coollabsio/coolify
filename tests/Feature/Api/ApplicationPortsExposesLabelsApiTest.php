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
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'ports-exposes-api-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://app.example.com',
        'ports_exposes' => '80',
    ]);
    $this->application->settings->update(['is_container_label_readonly_enabled' => true]);
    $this->application->update([
        'custom_labels' => base64_encode(implode("\n", generateLabelsApplication($this->application->fresh()))),
    ]);
});

function patchPortsExposes(string $bearerToken, string $uuid, array $payload)
{
    return test()->withHeaders(['Authorization' => 'Bearer '.$bearerToken])
        ->patchJson("/api/v1/applications/{$uuid}", $payload);
}

test('regenerates the load balancer port label when ports_exposes changes', function () {
    expect((string) $this->application->fresh()->parseContainerLabels())
        ->toContain('loadbalancer.server.port=80');

    patchPortsExposes($this->bearerToken, $this->application->uuid, ['ports_exposes' => '9000'])
        ->assertOk();

    $labels = (string) $this->application->fresh()->parseContainerLabels();

    expect($labels)->toContain('loadbalancer.server.port=9000')
        ->not->toContain('loadbalancer.server.port=80');
});

test('keeps custom labels when readonly labels are disabled', function () {
    $this->application->settings->update(['is_container_label_readonly_enabled' => false]);
    $this->application->update(['custom_labels' => base64_encode('my.custom=label')]);

    patchPortsExposes($this->bearerToken, $this->application->uuid, ['ports_exposes' => '9000'])
        ->assertOk();

    expect((string) $this->application->fresh()->parseContainerLabels())->toBe('my.custom=label');
});
