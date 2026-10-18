<?php

use App\Actions\Proxy\SaveProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Livewire\Server\Proxy;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
});

function traefikCertificateResolverApplicationLabels(Server $server): array
{
    $project = Project::factory()->create(['team_id' => $server->team_id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $application = Application::factory()->createOne([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://example.com',
        'redirect' => 'both',
        'is_http_basic_auth_enabled' => false,
    ]);

    return generateLabelsApplication($application);
}

test('new servers keep the letsencrypt resolver on generated HTTPS routers', function () {
    $labels = collect(traefikCertificateResolverApplicationLabels($this->server));

    expect($labels->filter(fn ($label) => str_contains($label, '.tls.certresolver=letsencrypt')))->not->toBeEmpty();
});

test('servers without a resolver generate HTTPS routers without certresolver labels', function () {
    $this->server->settings->update(['traefik_certificate_resolver' => null]);

    $labels = collect(traefikCertificateResolverApplicationLabels($this->server->fresh()));

    expect($labels->filter(fn ($label) => str_contains($label, '.tls=true')))->not->toBeEmpty()
        ->and($labels->filter(fn ($label) => str_contains($label, 'certresolver')))->toBeEmpty();
});

test('servers with a custom resolver use it on generated HTTPS routers', function () {
    $this->server->settings->update(['traefik_certificate_resolver' => 'cloudflare']);

    $labels = collect(traefikCertificateResolverApplicationLabels($this->server->fresh()));

    expect($labels->filter(fn ($label) => str_contains($label, '.tls.certresolver=cloudflare')))->not->toBeEmpty()
        ->and($labels->filter(fn ($label) => str_contains($label, 'certresolver=letsencrypt')))->toBeEmpty();
});

test('coolify dashboard routes follow the server certificate resolver', function (?string $resolver, array $expectedTls) {
    Process::fake();
    InstanceSettings::query()->whereKey(0)->update(['fqdn' => 'https://coolify.example.com']);
    Once::flush();
    $this->server->update(['ip' => 'host.docker.internal']);
    $this->server->settings->update(['traefik_certificate_resolver' => $resolver]);

    $this->server->fresh()->setupDynamicProxyConfiguration();

    $command = '';
    Process::assertRan(function ($process) use (&$command) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return str_contains($command, 'coolify.yaml');
    });
    preg_match("/echo '([A-Za-z0-9+\/=]+)' \| base64 -d/", $command, $matches);
    $routers = Yaml::parse(base64_decode($matches[1]))['http']['routers'];

    foreach (['coolify-https', 'coolify-reverb-wss', 'coolify-reverb-api-https', 'coolify-terminal-wss'] as $router) {
        expect($routers[$router])->toHaveKey('tls')
            ->and($routers[$router]['tls'] ?? [])->toBe($expectedTls);
    }
})->with([
    'letsencrypt' => ['letsencrypt', ['certresolver' => 'letsencrypt']],
    'no resolver' => [null, []],
]);

test('the catch-all router follows the server certificate resolver', function (?string $resolver, array $expectedTls) {
    Process::fake();
    $this->server->settings->update(['traefik_certificate_resolver' => $resolver]);

    $this->server->fresh()->setupDefaultRedirect();

    $command = '';
    Process::assertRan(function ($process) use (&$command) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return str_contains($command, 'default_redirect_503.yaml') && str_contains($command, 'base64 -d');
    });
    preg_match("/echo '([A-Za-z0-9+\/=]+)' \| base64 -d/", $command, $matches);
    $catchall = Yaml::parse(base64_decode($matches[1]))['http']['routers']['catchall'];

    expect($catchall)->toHaveKey('tls')
        ->and($catchall['tls'] ?? [])->toBe($expectedTls);
})->with([
    'letsencrypt' => ['letsencrypt', ['certResolver' => 'letsencrypt']],
    'no resolver' => [null, []],
]);

test('proxy page saves an empty resolver as none and rejects unsafe names', function () {
    SaveProxyConfiguration::shouldRun();
    Process::fake();
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);

    Livewire::test(Proxy::class, ['server' => $this->server])
        ->assertSet('certificateResolver', 'letsencrypt')
        ->set('certificateResolver', 'letsencrypt.acme=x')
        ->call('instantSave')
        ->assertDispatched('error');

    expect($this->server->settings->fresh()->traefik_certificate_resolver)->toBe('letsencrypt');

    Livewire::test(Proxy::class, ['server' => $this->server->fresh()])
        ->set('certificateResolver', '')
        ->call('instantSave')
        ->assertDispatched('success');

    expect($this->server->settings->fresh()->traefik_certificate_resolver)->toBeNull();
});

function traefikCertificateResolverApplicationOverrideLabels(Server $server, ?string $applicationResolver): array
{
    $project = Project::factory()->create(['team_id' => $server->team_id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $application = Application::factory()->createOne([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://example.com',
        'redirect' => 'both',
        'is_http_basic_auth_enabled' => false,
    ]);
    $application->settings->update(['traefik_certificate_resolver' => $applicationResolver]);

    return generateLabelsApplication($application->fresh());
}

test('an application resolver overrides the server resolver on generated HTTPS routers', function (?string $applicationResolver, ?string $expectedResolver) {
    $this->server->settings->update(['traefik_certificate_resolver' => 'letsencrypt']);

    $labels = collect(traefikCertificateResolverApplicationOverrideLabels($this->server->fresh(), $applicationResolver));
    $resolverLabels = $labels->filter(fn ($label) => str_contains($label, 'certresolver'));

    expect($labels->filter(fn ($label) => str_contains($label, '.tls=true')))->not->toBeEmpty();

    if ($expectedResolver === null) {
        expect($resolverLabels)->toBeEmpty();

        return;
    }

    expect($resolverLabels)->not->toBeEmpty()
        ->and($resolverLabels->every(fn ($label) => str_ends_with($label, '.tls.certresolver='.$expectedResolver)))->toBeTrue();
})->with([
    'none turns the resolver off' => ['none', null],
    'custom resolver' => ['cloudflare', 'cloudflare'],
    'null uses the server resolver' => [null, 'letsencrypt'],
]);
