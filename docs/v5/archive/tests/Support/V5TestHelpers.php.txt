<?php

/*
|--------------------------------------------------------------------------
| Shared V5 Test Helpers
|--------------------------------------------------------------------------
|
| Global Pest helper functions shared by the tests/Feature/V5 and
| tests/Unit/V5 suites. Loaded from tests/Pest.php. The v5_* table
| definitions themselves live in Tests\Support\V5TestSchema so they cannot
| drift from the migrations per test file.
|
*/

use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Flux\AgentTokenIssuer;
use App\Services\Flux\FluxClient;
use App\Services\Flux\FluxHealth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\Support\V5TestSchema;

/**
 * The shared per-test reset used by the split V5 dashboard feature tests
 * (previously the DashboardTest beforeEach).
 */
function resetV5DashboardTestState(): void
{
    Config::set('app.maintenance.store', 'array');
    Config::set('broadcasting.default', 'log');
    Config::set('cache.default', 'array');
    Config::set('flux.bootstrap_host_connection_timeout_seconds', 0);

    V5TestSchema::dropAllTables();
    Schema::dropIfExists('private_keys');
    Schema::dropIfExists('team_user');
    Schema::dropIfExists('teams');
    Schema::dropIfExists('users');
}

function fakeFluxHealth(bool $available = true, string $message = 'Flux is running.'): void
{
    app()->instance(FluxHealth::class, Mockery::mock(FluxHealth::class, function (MockInterface $mock) use ($available, $message) {
        $mock->shouldReceive('check')
            ->once()
            ->andReturn([
                'available' => $available,
                'label' => $available ? 'Running' : 'Unavailable',
                'message' => $message,
                'socket' => '/run/coolify/flux.sock',
            ]);
    }));
}

function fakeAgentTokenIssuer(string $token = 'signed-host-jwt'): void
{
    $issuer = Mockery::mock(AgentTokenIssuer::class);
    $issuer->shouldReceive('issueForServer')->andReturn($token);
    app()->instance(AgentTokenIssuer::class, $issuer);
}

function fakeSuccessfulNginxFluxDeployment(string $image = 'docker.io/library/nginx:alpine'): void
{
    $mock = Mockery::mock(FluxClient::class, function (MockInterface $mock) use ($image): void {
        $mock->shouldReceive('pullImage')
            ->once()
            ->with(Mockery::type('string'), $image)
            ->andReturn('Image pulled.');
        $mock->shouldReceive('createContainer')
            ->once()
            ->with(Mockery::type('string'), Mockery::on(fn (array $spec): bool => ($spec['image'] ?? null) === $image
                && ($spec['networks'] ?? []) === ['coolify-default-mesh']
                && ($spec['dns_search'] ?? []) === ['default.coolify.internal']
                && in_array($spec['name'] ?? '', $spec['network_aliases'] ?? [], true)))
            ->andReturn('nginx-container-id');
        $mock->shouldReceive('startContainer')
            ->once()
            ->with(Mockery::type('string'), 'nginx-container-id')
            ->andReturn('Container started.');
        $mock->shouldReceive('inspectContainer')
            ->once()
            ->with(Mockery::type('string'), 'nginx-container-id')
            ->andReturn(['State' => ['Running' => true]]);
    });

    app()->instance(FluxClient::class, $mock);
}

function expectCaddyIngressFirewallRule(mixed $fluxClient): void
{
    $fluxClient
        ->shouldReceive('applyFirewallRule')
        ->once()
        ->with(Mockery::type('string'), [
            'id' => 'v5-caddy-ingress:80',
            'namespace' => 'default',
            'src' => '0.0.0.0/0',
            'dst' => 'coolify-v5-caddy',
            'proto' => 'tcp',
            'port' => 80,
        ])
        ->andReturn('Firewall rule applied.');
    $fluxClient
        ->shouldNotReceive('applyFirewallRule')
        ->with(Mockery::type('string'), Mockery::on(fn (array $rule): bool => ($rule['port'] ?? null) === 443));
}

function createSharedUserAndTeamTables(): void
{
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name')->default('Anonymous');
        $table->string('email');
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    Schema::create('teams', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('description')->nullable();
        $table->boolean('personal_team')->default(false);
        $table->boolean('show_boarding')->default(false);
        $table->timestamps();
    });

    Schema::create('private_keys', function ($table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->string('name');
        $table->string('description')->nullable();
        $table->longText('private_key');
        $table->string('fingerprint')->nullable();
        $table->boolean('is_git_related')->default(false);
        $table->foreignId('team_id');
        $table->timestamps();
    });

    Schema::create('projects', function ($table) {
        $table->id();
        $table->string('uuid');
        $table->string('name');
        $table->text('description')->nullable();
        $table->foreignId('team_id');
        $table->timestamps();
    });

    Schema::create('environments', function ($table) {
        $table->id();
        $table->string('name');
        $table->foreignId('project_id');
        $table->timestamps();
        $table->text('description')->nullable();
        $table->string('uuid');
    });

    V5TestSchema::createAllTables();

    Schema::create('team_user', function ($table) {
        $table->id();
        $table->foreignId('team_id');
        $table->foreignId('user_id');
        $table->string('role')->default('member');
        $table->timestamps();

        $table->unique(['team_id', 'user_id']);
    });
}

/**
 * @param  array<int, string>  $command
 */
function cliFlagValue(array $command, string $flag): ?string
{
    $index = array_search($flag, $command, true);

    if ($index === false || ! isset($command[$index + 1])) {
        return null;
    }

    return $command[$index + 1];
}

/**
 * @return array{0: Project, 1: Environment}
 */
function createV5ProjectWithEnvironment(Team $team, string $projectName, string $environmentName): array
{
    $project = Project::withoutEvents(fn () => Project::query()->forceCreate([
        'uuid' => str($projectName)->slug().'-uuid',
        'name' => $projectName,
        'description' => null,
        'team_id' => $team->id,
    ]));

    $environment = Environment::withoutEvents(fn () => Environment::query()->forceCreate([
        'uuid' => str($environmentName)->slug().'-uuid',
        'name' => $environmentName,
        'description' => null,
        'project_id' => $project->id,
    ]));

    return [$project, $environment];
}

function createV5PrivateKey(Team $team, string $name): PrivateKey
{
    return PrivateKey::withoutEvents(fn () => PrivateKey::query()->forceCreate([
        'uuid' => str($name)->slug().'-uuid',
        'name' => $name,
        'description' => null,
        'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest-key\n-----END OPENSSH PRIVATE KEY-----\n",
        'fingerprint' => str($name)->slug()->toString(),
        'is_git_related' => false,
        'team_id' => $team->id,
    ]));
}

/**
 * @return array{0: User, 1: Team}
 */
function createV5UserWithTeam(string $email = 'margaret@example.com'): array
{
    $user = User::withoutEvents(fn () => User::query()->create([
        'name' => 'Margaret Hamilton',
        'email' => $email,
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
    $team = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'V5 Tooling Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $user->teams()->attach($team, ['role' => 'owner']);

    return [$user, $team];
}
