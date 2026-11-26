<?php

use App\Livewire\Project\Service\Domains;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->withoutVite();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['is_dns_validation_enabled' => false]));

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $server = Server::factory()->create(['team_id' => $team->id, 'ip' => '203.0.113.10']);
    $server->settings()->update(['wildcard_domain' => 'http://example.com', 'generate_exact_labels' => false]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => Yaml::dump(['services' => ['garage' => [
            'image' => 'dxflrs/garage:v2.5.0',
            'environment' => [
                'GARAGE_S3_API_URL=$GARAGE_S3_API_URL',
                'GARAGE_WEB_URL=$GARAGE_WEB_URL',
                'GARAGE_ADMIN_URL=$GARAGE_ADMIN_URL',
            ],
        ]]]),
    ]);
    $this->garage = ServiceApplication::create([
        'name' => 'garage',
        'service_id' => $this->service->id,
        'image' => 'dxflrs/garage:v2.5.0',
    ]);
    foreach (['GARAGE_S3_API_URL' => '', 'GARAGE_WEB_URL' => '', 'GARAGE_ADMIN_URL' => 'https://admin.example.org'] as $key => $value) {
        $this->service->environment_variables()->create(['key' => $key, 'value' => $value]);
    }
});

/**
 * @return array<string, int|null> public host => internal port of its HTTPS router
 */
function garageHttpsRoutes(Service $service): array
{
    $labels = Yaml::parse($service->refresh()->docker_compose)['services']['garage']['labels'];
    $ports = [];
    $hosts = [];
    foreach ($labels as $label) {
        if (preg_match('/^traefik\.http\.services\.(https-\d+-\S+)\.loadbalancer\.server\.port=(\d+)$/', $label, $match)) {
            $ports[$match[1]] = (int) $match[2];
        }
        if (preg_match('/^traefik\.http\.routers\.(https-\d+-\S+)\.rule=Host\(`([^`]+)`\)/', $label, $match)) {
            $hosts[$match[1]] = $match[2];
        }
    }

    return collect($hosts)->mapWithKeys(fn (string $host, string $router): array => [$host => $ports[$router] ?? null])->all();
}

it('routes the Garage endpoints to the same hosts and ports', function () {
    $uuid = $this->garage->uuid;

    $this->service->parse();

    expect(garageHttpsRoutes($this->service))->toBe([
        "s3-{$uuid}.example.com" => 3900,
        "web-{$uuid}.example.com" => 3902,
        'admin.example.org' => 3903,
    ])
        ->and($this->service->environment_variables()->where('key', 'like', 'GARAGE_%_URL')->orderBy('key')->pluck('value', 'key')->all())->toBe([
            'GARAGE_ADMIN_URL' => 'https://admin.example.org',
            'GARAGE_S3_API_URL' => "https://s3-{$uuid}.example.com",
            'GARAGE_WEB_URL' => "https://web-{$uuid}.example.com",
        ]);
});

it('shows the routed Garage endpoints on the Domains page', function () {
    $uuid = $this->garage->uuid;
    $this->service->parse();

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSee("https://s3-{$uuid}.example.com")
        ->assertSee("https://web-{$uuid}.example.com")
        ->assertSee('https://admin.example.org')
        ->assertDontSee('No domains configured');
});
