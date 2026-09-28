<?php

use App\Livewire\Server\DockerRegistries;
use App\Livewire\Server\DockerRegistries\Login;
use App\Livewire\Server\DockerRegistries\ServerRegistries;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->withoutVite();
    $this->withoutDefer();
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);

    $destination = $this->server->standaloneDockers()->firstOrFail();
    $environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->environment = $environment;
    $this->destination = $destination;
    $this->privateApp = Application::factory()->create([
        'name' => 'private-app',
        'docker_registry_image_name' => 'registry.example.com/team/app',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

function actingAsDockerRegistriesRole(Team $team, string $role): User
{
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return $user;
}

function makeDockerRegistriesServerReachable(Server $server): void
{
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $server->refresh();
}

it('shows which registries a server is logged in to and which ones its applications need', function () {
    actingAsDockerRegistriesRole($this->team, 'admin');
    makeDockerRegistriesServerReachable($this->server);

    Process::fake(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return Process::result(output: str_contains($command, '.docker/config.json')
            ? json_encode(['auths' => ['ghcr.io' => ['auth' => base64_encode('user:SECRET_TOKEN')]]])
            : '');
    });

    $component = Livewire::withoutLazyLoading()->test(ServerRegistries::class, ['server' => $this->server])
        ->assertSet('error', null)
        ->assertSet('registries', [
            ['registry' => 'ghcr.io', 'logged_in' => true, 'source' => 'auths', 'username' => 'user', 'used_by' => []],
            [
                'registry' => 'registry.example.com',
                'logged_in' => false,
                'source' => null,
                'username' => null,
                'used_by' => [['type' => 'Application', 'name' => 'private-app', 'link' => $this->privateApp->link()]],
            ],
        ])
        ->assertSee('Logged in')
        ->assertSee('Not logged in')
        ->assertSee('private-app');

    expect($component->html())->not->toContain('SECRET_TOKEN')
        ->and(json_encode($component->instance()->registries))->not->toContain('SECRET_TOKEN');
});

it('shows an error and keeps the application registries when the server is not reachable', function () {
    actingAsDockerRegistriesRole($this->team, 'admin');
    Process::fake();

    Livewire::withoutLazyLoading()->test(ServerRegistries::class, ['server' => $this->server])
        ->assertSet('error', 'The server is not reachable. Validate the server to read its registry logins.')
        ->assertSee('registry.example.com')
        ->assertSee('Unknown');

    Process::assertNothingRan();
});

it('lists the team servers for admins on the registries page', function () {
    actingAsDockerRegistriesRole($this->team, 'admin');

    Livewire::test(DockerRegistries::class)
        ->assertViewHas('servers', fn ($servers) => $servers->pluck('id')->all() === [$this->server->id]);

    $this->get(route('registries.index'))
        ->assertOk()
        ->assertSee('href="'.route('registries.index').'"', false);

    $this->get(route('server.registries', ['server_uuid' => $this->server->uuid]))
        ->assertOk()
        ->assertSee('href="'.route('server.registries', ['server_uuid' => $this->server->uuid]).'"', false);
});

it('does not show registry logins to members', function () {
    actingAsDockerRegistriesRole($this->team, 'member');

    Livewire::test(DockerRegistries::class)->assertForbidden();

    $this->get(route('server.show', ['server_uuid' => $this->server->uuid]))
        ->assertOk()
        ->assertDontSee('href="'.route('registries.index').'"', false)
        ->assertDontSee('href="'.route('server.registries', ['server_uuid' => $this->server->uuid]).'"', false);

    $this->get(route('server.registries', ['server_uuid' => $this->server->uuid]))->assertForbidden();

    Livewire::withoutLazyLoading()->test(ServerRegistries::class, ['server' => $this->server])->assertForbidden();
});

it('does not show registry logins of another team server', function () {
    actingAsDockerRegistriesRole(Team::factory()->create(), 'owner');

    Livewire::test(DockerRegistries::class)
        ->assertViewHas('servers', fn ($servers) => $servers->isEmpty());

    Livewire::withoutLazyLoading()->test(ServerRegistries::class, ['server' => $this->server])->assertForbidden();
});

it('reads the Docker config of a non-root server user through sudo', function () {
    $server = Server::factory()->make(['user' => 'deploy']);

    $commands = parseCommandsByLineForSudo(collect(['cat "$HOME/.docker/config.json" 2>/dev/null || true']), $server);

    expect($commands)->toBe(['sudo cat "$HOME/.docker/config.json" 2>/dev/null || sudo true']);
});

/**
 * @return array<int, string>
 */
function fakeDockerRegistryServer(string $loginError = ''): array
{
    $commands = [];
    Process::fake(function ($process) use (&$commands, $loginError) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $commands[] = $command;

        if ($loginError !== '' && str_contains($command, ' login ')) {
            return Process::result(errorOutput: $loginError, exitCode: 1);
        }

        return Process::result(output: str_contains($command, '.docker/config.json') && str_contains($command, 'cat ')
            ? json_encode(['auths' => ['ghcr.io' => ['auth' => 'x']]])
            : '');
    });

    return $commands;
}

it('logs in to a registry with the token on stdin and never as plain text in the command', function () {
    actingAsDockerRegistriesRole($this->team, 'admin');
    makeDockerRegistriesServerReachable($this->server);
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(output: '');
    });
    $token = "ghp_SECRET'token\"with\$special";

    Livewire::test(Login::class, ['server' => $this->server])
        ->set('registry', 'https://GHCR.io/')
        ->set('username', 'octocat')
        ->set('password', $token)
        ->call('login')
        ->assertHasNoErrors()
        ->assertSet('password', '')
        ->assertSet('registry', '')
        ->assertDispatched('success')
        ->assertDispatched('close-modal')
        ->assertDispatched('registryLoginsChanged', serverId: $this->server->id);

    $login = collect($commands)->first(fn (string $command) => str_contains($command, ' login '));
    expect($login)
        ->toContain("echo '".base64_encode($token)."' | base64 -d | docker --config \"\$HOME/.docker\" login --username 'octocat' --password-stdin 'ghcr.io'")
        ->not->toContain('ghp_SECRET');

    $event = AuditEvent::query()->where('event', 'ui.server.registry_login')->sole();
    expect($event->metadata['registry'])->toBe('ghcr.io')
        ->and($event->metadata['outcome'])->toBe('success')
        ->and(json_encode($event->metadata))->not->toContain('ghp_SECRET');
});

it('lets docker choose the Docker Hub key and runs the login through sudo for non-root users', function () {
    actingAsDockerRegistriesRole($this->team, 'admin');
    $this->server->update(['user' => 'deploy']);
    makeDockerRegistriesServerReachable($this->server);
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(output: '');
    });

    Livewire::test(Login::class, ['server' => $this->server])
        ->set('registry', 'docker.io')
        ->set('username', 'someone')
        ->set('password', 'dckr_pat_SECRET')
        ->call('login')
        ->assertHasNoErrors();

    $login = collect($commands)->first(fn (string $command) => str_contains($command, ' login '));
    expect($login)->toContain("echo '".base64_encode('dckr_pat_SECRET')."' | sudo base64 -d | sudo docker --config \"\$HOME/.docker\" login --username 'someone' --password-stdin\n");
});

it('shows the docker error without the token when the login fails', function () {
    actingAsDockerRegistriesRole($this->team, 'admin');
    makeDockerRegistriesServerReachable($this->server);
    fakeDockerRegistryServer("ControlSocket /tmp/mux already exists, disabling multiplexing\nWARNING! Using --password via the CLI is insecure.\nError response from daemon: unauthorized: bad token SECRET_TOKEN");

    Livewire::test(Login::class, ['server' => $this->server])
        ->set('registry', 'ghcr.io')
        ->set('username', 'octocat')
        ->set('password', 'SECRET_TOKEN')
        ->call('login')
        ->assertSet('password', '')
        ->assertSet('registry', 'ghcr.io')
        ->assertDispatched('error', 'Login failed.', 'Error response from daemon: unauthorized: bad token ***');

    expect(AuditEvent::query()->where('event', 'ui.server.registry_login')->sole()->metadata['outcome'])->toBe('failed');
});

it('validates the registry host and username before running a command', function (string $registry, string $username, string $errorField) {
    actingAsDockerRegistriesRole($this->team, 'admin');
    makeDockerRegistriesServerReachable($this->server);
    $commands = fakeDockerRegistryServer();

    Livewire::test(Login::class, ['server' => $this->server])
        ->set('registry', $registry)
        ->set('username', $username)
        ->set('password', 'token')
        ->call('login')
        ->assertHasErrors($errorField);

    Process::assertDidntRun(fn ($process) => str_contains($process->command, ' login '));
})->with([
    'shell characters in registry' => ["ghcr.io';reboot;'", 'user', 'registry'],
    'space in username' => ['ghcr.io', 'my user', 'username'],
    'empty registry' => ['', 'user', 'registry'],
]);

it('logs out from a registry', function () {
    actingAsDockerRegistriesRole($this->team, 'admin');
    makeDockerRegistriesServerReachable($this->server);
    fakeDockerRegistryServer();

    Livewire::withoutLazyLoading()->test(ServerRegistries::class, ['server' => $this->server])
        ->assertSee('Log out')
        ->assertSeeHtml('logout(ghcr.io)')
        ->call('logout', 'ghcr.io', '')
        ->assertDispatched('success');

    Process::assertRan(fn ($process) => str_contains($process->command, "docker --config \"\$HOME/.docker\" logout 'ghcr.io'"));
    expect(AuditEvent::query()->where('event', 'ui.server.registry_logout')->sole()->metadata['registry'])->toBe('ghcr.io');
});

it('does not let a user who lost the admin role log in or log out', function (string $action) {
    $user = actingAsDockerRegistriesRole($this->team, 'admin');
    makeDockerRegistriesServerReachable($this->server);
    fakeDockerRegistryServer();
    $component = $action === 'login'
        ? Livewire::test(Login::class, ['server' => $this->server])->set('registry', 'ghcr.io')->set('username', 'u')->set('password', 'p')
        : Livewire::withoutLazyLoading()->test(ServerRegistries::class, ['server' => $this->server]);

    $user->teams()->updateExistingPivot($this->team->id, ['role' => 'member']);
    $user->refresh();
    Cache::flush();

    $action === 'login'
        ? $component->call('login')->assertForbidden()
        : $component->call('logout', 'ghcr.io')->assertForbidden();

    Process::assertDidntRun(fn ($process) => str_contains($process->command, ' login ') || str_contains($process->command, ' logout '));
})->with(['login', 'logout']);

it('does not open the login form for members', function () {
    actingAsDockerRegistriesRole($this->team, 'member');

    Livewire::test(Login::class, ['server' => $this->server])->assertForbidden();
});

it('reloads only the card of the server that got a new login', function () {
    actingAsDockerRegistriesRole($this->team, 'admin');
    makeDockerRegistriesServerReachable($this->server);
    fakeDockerRegistryServer();
    $component = Livewire::withoutLazyLoading()->test(ServerRegistries::class, ['server' => $this->server])
        ->set('registries', []);

    $component->dispatch('registryLoginsChanged', serverId: $this->server->id + 1000)
        ->assertSet('registries', []);

    $component->dispatch('registryLoginsChanged', serverId: $this->server->id)
        ->assertSet('registries', fn (array $registries) => collect($registries)->pluck('registry')->all() === ['ghcr.io', 'registry.example.com']);
});

it('fills the registry and username from the chosen provider', function (string $provider, string $registry, string $username) {
    actingAsDockerRegistriesRole($this->team, 'admin');

    Livewire::test(Login::class, ['server' => $this->server])
        ->set('password', 'typed-before')
        ->set('provider', $provider)
        ->assertSet('registry', $registry)
        ->assertSet('username', $username)
        ->assertSet('password', '');
})->with([
    'docker hub' => ['dockerhub', 'docker.io', ''],
    'google' => ['google', '', '_json_key'],
    'aws' => ['aws', '', 'AWS'],
    'unknown value' => ['nope', '', ''],
]);

it('edits an existing login with the registry kept fixed', function () {
    actingAsDockerRegistriesRole($this->team, 'admin');
    makeDockerRegistriesServerReachable($this->server);
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(output: '');
    });

    Livewire::test(Login::class, ['server' => $this->server, 'editRegistry' => 'ghcr.io', 'currentUsername' => 'octocat'])
        ->assertSet('provider', 'ghcr')
        ->assertSet('registry', 'ghcr.io')
        ->assertSet('username', 'octocat')
        ->assertSee('Update login')
        ->set('registry', 'evil.example.com')
        ->set('provider', 'dockerhub')
        ->assertSet('registry', 'ghcr.io')
        ->set('username', 'new-user')
        ->set('password', 'NEW_SECRET')
        ->call('login')
        ->assertHasNoErrors()
        ->assertSet('registry', 'ghcr.io')
        ->assertSet('username', 'new-user')
        ->assertSet('password', '')
        ->assertDispatched('success', 'Updated the login for ghcr.io.');

    $login = collect($commands)->first(fn (string $command) => str_contains($command, ' login '));
    expect($login)->toContain("login --username 'new-user' --password-stdin 'ghcr.io'")
        ->not->toContain('evil.example.com');
    expect(AuditEvent::query()->where('event', 'ui.server.registry_login_updated')->sole()->metadata['registry'])->toBe('ghcr.io');
});

it('does not allow changing the edited registry from the browser', function () {
    actingAsDockerRegistriesRole($this->team, 'admin');

    expect(fn () => Livewire::test(Login::class, ['server' => $this->server, 'editRegistry' => 'ghcr.io'])
        ->set('editRegistry', 'evil.example.com'))->toThrow(Exception::class);
});

it('shows an edit button only for logins stored in the Docker config', function () {
    actingAsDockerRegistriesRole($this->team, 'admin');
    makeDockerRegistriesServerReachable($this->server);
    Process::fake(fn () => Process::result(output: json_encode([
        'auths' => ['ghcr.io' => ['auth' => base64_encode('octocat:SECRET_TOKEN')]],
        'credHelpers' => ['123456789012.dkr.ecr.eu-west-1.amazonaws.com' => 'ecr-login'],
    ])));

    $html = Livewire::withoutLazyLoading()->test(ServerRegistries::class, ['server' => $this->server])->html();

    expect(substr_count($html, 'Edit login for'))->toBe(1)
        ->and($html)->toContain('Edit login for ghcr.io')
        ->and($html)->not->toContain('SECRET_TOKEN');
});

it('guesses the provider of an existing registry', function (string $registry, string $provider) {
    expect(Login::providerFor($registry))->toBe($provider);
})->with([
    ['docker.io', 'dockerhub'],
    ['europe-west1-docker.pkg.dev', 'google'],
    ['ghcr.io', 'ghcr'],
    ['registry.gitlab.com', 'gitlab'],
    ['myregistry.azurecr.io', 'azure'],
    ['123456789012.dkr.ecr.eu-west-1.amazonaws.com', 'aws'],
    ['registry.example.com', 'custom'],
]);

it('always uses the fixed host of a provider, even if the browser sends another one', function () {
    actingAsDockerRegistriesRole($this->team, 'admin');
    makeDockerRegistriesServerReachable($this->server);
    $commands = fakeDockerRegistryServer();

    Livewire::test(Login::class, ['server' => $this->server])
        ->set('provider', 'ghcr')
        ->set('registry', 'evil.example.com')
        ->set('username', 'octocat')
        ->set('password', 'token')
        ->call('login')
        ->assertHasNoErrors()
        ->assertDispatched('success', 'Logged in to ghcr.io.');

    Process::assertRan(fn ($process) => str_contains($process->command, "--password-stdin 'ghcr.io'"));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'evil.example.com'));
});

it('checks that the host belongs to the chosen cloud provider', function (string $provider, string $registry, bool $valid) {
    actingAsDockerRegistriesRole($this->team, 'admin');
    makeDockerRegistriesServerReachable($this->server);
    fakeDockerRegistryServer();

    $component = Livewire::test(Login::class, ['server' => $this->server])
        ->set('provider', $provider)
        ->set('registry', $registry)
        ->set('username', 'user')
        ->set('password', 'token')
        ->call('login');

    $valid ? $component->assertHasNoErrors() : $component->assertHasErrors('registry');
})->with([
    'google artifact registry' => ['google', 'europe-west1-docker.pkg.dev', true],
    'google container registry' => ['google', 'eu.gcr.io', true],
    'google with other host' => ['google', 'ghcr.io', false],
    'azure' => ['azure', 'myregistry.azurecr.io', true],
    'azure with other host' => ['azure', 'azurecr.io.example.com', false],
    'aws' => ['aws', '123456789012.dkr.ecr.eu-west-1.amazonaws.com', true],
    'aws china' => ['aws', '123456789012.dkr.ecr.cn-north-1.amazonaws.com.cn', true],
    'aws with other host' => ['aws', 'dkr.ecr.eu-west-1.amazonaws.com', false],
    'other accepts any host' => ['custom', 'registry.example.com:5000', true],
]);

it('returns 403 on the registries pages for members', function () {
    actingAsDockerRegistriesRole($this->team, 'member');

    $this->get(route('registries.index'))->assertForbidden();
    $this->get(route('server.registries', ['server_uuid' => $this->server->uuid]))->assertForbidden();
});

it('does not open the registries page of another team server', function () {
    actingAsDockerRegistriesRole(Team::factory()->create(), 'owner');

    $response = $this->get(route('server.registries', ['server_uuid' => $this->server->uuid]));

    expect($response->status())->toBeIn([403, 404])
        ->and($response->getContent())->not->toContain('registry.example.com');
    Livewire::test(Login::class, ['server' => $this->server])->assertForbidden();
});

it('does not let the browser swap the server of a card or of the login form', function (string $component) {
    actingAsDockerRegistriesRole($this->team, 'admin');
    makeDockerRegistriesServerReachable($this->server);
    fakeDockerRegistryServer();
    $otherServer = Server::factory()->create([
        'team_id' => Team::factory()->create()->id,
        'private_key_id' => PrivateKey::factory()->create()->id,
    ]);

    $test = $component === 'card'
        ? Livewire::withoutLazyLoading()->test(ServerRegistries::class, ['server' => $this->server])
        : Livewire::test(Login::class, ['server' => $this->server]);

    expect(fn () => $test->set('server', $otherServer->id))->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['card', 'login form']);

it('sends only registry names and usernames to the browser, never secrets from the Docker config', function () {
    actingAsDockerRegistriesRole($this->team, 'admin');
    $this->server->update(['ip' => '203.0.113.77']);
    makeDockerRegistriesServerReachable($this->server);
    Process::fake(fn () => Process::result(output: json_encode([
        'auths' => ['ghcr.io' => ['auth' => base64_encode('octocat:SECRET_TOKEN'), 'identitytoken' => 'ID_TOKEN_SECRET']],
        'credsStore' => 'pass',
        'proxies' => ['default' => ['httpProxy' => 'http://proxyuser:PROXY_SECRET@proxy']],
    ])));

    $card = Livewire::withoutLazyLoading()->test(ServerRegistries::class, ['server' => $this->server]);
    $form = Livewire::test(Login::class, ['server' => $this->server, 'editRegistry' => 'ghcr.io', 'currentUsername' => 'octocat']);

    expect(array_keys($card->snapshot['data']))->toBe(['server', 'registries', 'error'])
        ->and(array_keys($form->snapshot['data']))->toBe(['server', 'provider', 'editRegistry', 'registry', 'username', 'password'])
        ->and($form->snapshot['data']['password'])->toBe('');

    foreach ([$card, $form] as $component) {
        $payload = json_encode($component->snapshot).$component->html();
        expect($payload)
            ->not->toContain('SECRET_TOKEN')
            ->not->toContain(base64_encode('octocat:SECRET_TOKEN'))
            ->not->toContain('ID_TOKEN_SECRET')
            ->not->toContain('PROXY_SECRET')
            ->not->toContain('203.0.113.77');
    }
});

it('lists databases and service containers that use a registry, and each service only once', function () {
    actingAsDockerRegistriesRole($this->team, 'admin');
    makeDockerRegistriesServerReachable($this->server);
    fakeDockerRegistryServer();
    $placement = [
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ];

    $database = StandalonePostgresql::create([
        'name' => 'private-db',
        'postgres_user' => 'postgres',
        'postgres_password' => encrypt('password'),
        'postgres_db' => 'app',
        'image' => 'ghcr.io/acme/postgres:16',
    ] + $placement);
    StandalonePostgresql::create([
        'name' => 'coolify-db',
        'postgres_user' => 'postgres',
        'postgres_password' => encrypt('password'),
        'postgres_db' => 'coolify',
        'image' => 'ghcr.io/acme/internal:1',
    ] + $placement);
    $service = Service::factory()->create(['name' => 'private-stack', 'server_id' => $this->server->id] + $placement);
    foreach (['web' => 'ghcr.io/acme/web:1', 'worker' => 'ghcr.io/acme/worker:1', 'cache' => 'redis:7'] as $name => $image) {
        ServiceApplication::create(['uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => $name, 'image' => $image]);
    }
    ServiceDatabase::create(['uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'db', 'image' => 'ghcr.io/acme/db:1']);

    $registries = collect(Livewire::withoutLazyLoading()->test(ServerRegistries::class, ['server' => $this->server])
        ->assertSee('private-db')
        ->assertSee('private-stack')
        ->get('registries'))->keyBy('registry');

    expect($registries['ghcr.io']['used_by'])->toBe([
        ['type' => 'Database', 'name' => 'private-db', 'link' => $database->link()],
        ['type' => 'Service', 'name' => 'private-stack', 'link' => $service->link()],
    ])
        ->and($registries['docker.io']['used_by'])->toBe([
            ['type' => 'Service', 'name' => 'private-stack', 'link' => $service->link()],
        ])
        ->and($registries['docker.io']['logged_in'])->toBeFalse()
        ->and($registries['registry.example.com']['used_by'][0]['type'])->toBe('Application');
});
