<?php

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0]);

    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();

    $this->application = Application::factory()->createOne([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://app.example.com,http://plain.example.com',
        'ports_exposes' => '3000',
    ]);
});

/**
 * Traefik answers 404 for a router whose service is not declared (#10546).
 *
 * @param  array<int, string>  $labels
 * @return array<string, string>
 */
function routersWithUndeclaredServices(array $labels): array
{
    $declared = collect($labels)
        ->map(fn (string $label) => preg_match('/^traefik\.http\.services\.([^.]+)\.loadbalancer\.server\.port=\d+$/', $label, $m) ? $m[1] : null)
        ->filter();

    return collect($labels)
        ->mapWithKeys(fn (string $label) => preg_match('/^traefik\.http\.routers\.([^.]+)\.service=(.+)$/', $label, $m) ? [$m[1] => $m[2]] : [])
        ->reject(fn (string $service) => $declared->contains($service))
        ->all();
}

test('every generated application router points to a declared service', function (bool $forceHttps) {
    $this->application->settings->update(['is_force_https_enabled' => $forceHttps]);

    $labels = generateLabelsApplication($this->application->fresh());

    expect($labels)
        ->toContain('traefik.http.routers.http-0-'.$this->application->uuid.'.service=http-0-'.$this->application->uuid)
        ->toContain('traefik.http.services.http-0-'.$this->application->uuid.'.loadbalancer.server.port=3000')
        ->and(routersWithUndeclaredServices($labels))->toBe([]);
})->with(['force https' => true, 'no force https' => false]);
