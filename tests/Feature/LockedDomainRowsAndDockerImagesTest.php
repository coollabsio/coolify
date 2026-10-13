<?php

use App\Livewire\Project\Application\Domains as ApplicationDomains;
use App\Livewire\Project\Application\PreviewDomains;
use App\Livewire\Project\Service\Domains;
use App\Livewire\Server\DockerImages;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->withoutVite();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0, 'is_dns_validation_enabled' => false]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id, 'ip' => '203.0.113.10']);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
});

it('rejects a client update of the service domain state', function (string $property, mixed $value) {
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
    ));
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
    $service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $environment->id,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
    ]);
    ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'service_id' => $service->id,
        'name' => 'web',
        'image' => 'nginx:alpine',
        'fqdn' => 'https://web.example.com',
    ]);

    Livewire::test(Domains::class, ['service' => $service->fresh(['applications', 'server'])])
        ->assertSet('domainRows', fn (array $rows): bool => collect($rows)->pluck('url')->all() === ['https://web.example.com'])
        ->set($property, $value);
})->with([
    'domain rows' => ['domainRows', [['url' => 'https://attacker.example.com']]],
    'dns validation' => ['dnsValidationEnabled', false],
])->throws(CannotUpdateLockedPropertyException::class);

it('rejects a client update of the docker image list', function () {
    $imageId = 'sha256:'.str_repeat('a', 64);
    Process::fake([
        '*docker image ls*' => Process::result(output: json_encode(['Repository' => 'nginx', 'Tag' => '1.27', 'ID' => $imageId, 'Size' => '52.5MB', 'CreatedSince' => '3 weeks ago'])),
        '*docker ps -a*' => Process::result(output: "{$imageId}#/web\n"),
        '*docker system df*' => Process::result(output: ''),
        '*docker image rm*' => Process::result(output: 'Deleted'),
    ]);

    Livewire::test(DockerImages::class, ['server_uuid' => $this->server->uuid])
        ->call('load')
        ->assertSet('images.0.containers', ['web'])
        ->set('images.0.containers', []);
})->throws(CannotUpdateLockedPropertyException::class);

function createLockedDomainRowsApplication(Server $server, Team $team): Application
{
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
    ));
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://app.example.com',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
    ]);
}

it('rejects a client update of the application domain rows', function (string $property, mixed $value) {
    $application = createLockedDomainRowsApplication($this->server, $this->team);

    Livewire::test(ApplicationDomains::class, ['application' => $application->fresh()])
        ->assertSet('domainRows', fn (array $rows): bool => collect($rows)->where('is_suggested', false)->pluck('url')->all() === ['https://app.example.com'])
        ->set($property, $value);
})->with([
    'domain rows' => ['domainRows', [['url' => 'https://app.example.com', 'service' => null, 'dns_status' => 'ok', 'dns_message' => 'faked', 'expected_ip' => null, 'needs_force_add' => true]]],
    'one row flag' => ['domainRows.0.needs_force_add', true],
    'forced suggestion index' => ['forceAddSuggestedIndex', 0],
    'DNS validation flag' => ['dnsValidationEnabled', false],
])->throws(CannotUpdateLockedPropertyException::class);

it('rejects a client update of the preview domain rows', function () {
    $application = createLockedDomainRowsApplication($this->server, $this->team);
    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 7,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/7',
        'fqdn' => 'https://pr-7.example.com',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->assertSet('domainRows', fn (array $rows): bool => collect($rows)->pluck('url')->all() === ['https://pr-7.example.com'])
        ->set('domainRows.0.dns_status', 'ok');
})->throws(CannotUpdateLockedPropertyException::class);
