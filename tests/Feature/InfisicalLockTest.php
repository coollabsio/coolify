<?php

use App\Exceptions\InfisicalManagedVariableException;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InfisicalConnection;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Services\Infisical\InfisicalLock;
use Illuminate\Foundation\Testing\RefreshDatabase;

// tests/Pest.php applies uses(TestCase::class) to tests/Feature but NOT
// RefreshDatabase — each file declares it individually. Without this the first
// Team::factory()->create() dies with "no such table: teams" against
// sqlite::memory:.
uses(RefreshDatabase::class);

beforeEach(function () {
    InfisicalLock::resetDepthForTesting();

    InstanceSettings::unguarded(function () {
        InstanceSettings::updateOrCreate(['id' => 0], []);
    });

    $this->infraTeam = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->infraTeam->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->infraTeam->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create([
            'server_id' => $this->server->id,
            'network' => 'coolify-test',
        ]);
});

/**
 * Builds a resource under an UNLOCKED team, then arms the lock afterwards.
 *
 * Creating the resource first keeps this task's tests focused on the guard:
 * resource creation itself performs Coolify-generated writes, which only
 * become legal in Task 5.
 */
function lockedOwner(string $class): object
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = test()->destination;

    $resource = match ($class) {
        Application::class => Application::factory()->create([
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockerfile',
        ]),
        Service::class => makeService($environment->id, $destination->id),
        ServiceApplication::class => ServiceApplication::create([
            'name' => 'svcapp-'.fake()->unique()->word(),
            'service_id' => makeService($environment->id, $destination->id)->id,
        ]),
        default => $class::create(array_merge([
            'name' => 'db-'.fake()->unique()->word(),
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => StandaloneDocker::class,
        ], standaloneRequiredColumns($class))),
    };

    InfisicalConnection::factory()->create(['team_id' => $team->id, 'is_enabled' => true]);

    return $resource;
}

function makeService(int $environmentId, int $destinationId): Service
{
    return Service::create([
        'name' => 'svc-'.fake()->unique()->word(),
        'environment_id' => $environmentId,
        'destination_id' => $destinationId,
        'destination_type' => StandaloneDocker::class,
        'docker_compose_raw' => "services:\n  app:\n    image: nginx\n",
    ]);
}

/**
 * @return array<string, string>
 */
function standaloneRequiredColumns(string $class): array
{
    return match ($class) {
        StandalonePostgresql::class => ['postgres_password' => 'secret'],
        StandaloneMysql::class => ['mysql_root_password' => 'secret', 'mysql_password' => 'secret'],
        StandaloneMariadb::class => ['mariadb_root_password' => 'secret', 'mariadb_password' => 'secret'],
        StandaloneMongodb::class => ['mongo_initdb_root_password' => 'secret'],
        StandaloneRedis::class => ['redis_password' => 'secret'],
        StandaloneKeydb::class => ['keydb_password' => 'secret'],
        StandaloneDragonfly::class => ['dragonfly_password' => 'secret'],
        StandaloneClickhouse::class => ['clickhouse_admin_password' => 'secret'],
        default => [],
    };
}

it('allows human writes when no connection exists', function () {
    $team = Team::factory()->create();

    expect(fn () => SharedEnvironmentVariable::factory()->create([
        'team_id' => $team->id, 'type' => 'team',
    ]))->not->toThrow(InfisicalManagedVariableException::class);
});

it('allows human writes when the connection is disabled', function () {
    $team = Team::factory()->create();
    InfisicalConnection::factory()->create(['team_id' => $team->id, 'is_enabled' => false]);

    expect(fn () => SharedEnvironmentVariable::factory()->create([
        'team_id' => $team->id, 'type' => 'team',
    ]))->not->toThrow(InfisicalManagedVariableException::class);
});

it('rejects a human write when the connection is enabled', function () {
    $team = Team::factory()->create();
    InfisicalConnection::factory()->create(['team_id' => $team->id, 'is_enabled' => true]);

    expect(fn () => SharedEnvironmentVariable::factory()->create([
        'team_id' => $team->id, 'type' => 'team',
    ]))->toThrow(InfisicalManagedVariableException::class);
});

it('rejects a human delete when the connection is enabled', function () {
    $team = Team::factory()->create();
    $variable = InfisicalLock::asSystem(fn () => SharedEnvironmentVariable::factory()->create([
        'team_id' => $team->id, 'type' => 'team',
    ]));
    InfisicalConnection::factory()->create(['team_id' => $team->id, 'is_enabled' => true]);

    expect(fn () => $variable->delete())->toThrow(InfisicalManagedVariableException::class);
});

it('allows a system write when the connection is enabled', function () {
    $team = Team::factory()->create();
    InfisicalConnection::factory()->create(['team_id' => $team->id, 'is_enabled' => true]);

    expect(fn () => InfisicalLock::asSystem(fn () => SharedEnvironmentVariable::factory()->create([
        'team_id' => $team->id, 'type' => 'team',
    ])))->not->toThrow(InfisicalManagedVariableException::class);
});

it('restores the lock after asSystem returns', function () {
    InfisicalLock::asSystem(fn () => null);

    expect(InfisicalLock::isSystemWrite())->toBeFalse();
});

it('restores the lock even when the system write throws', function () {
    try {
        InfisicalLock::asSystem(function () {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(InfisicalLock::isSystemWrite())->toBeFalse();
});

it('supports nested asSystem calls', function () {
    InfisicalLock::asSystem(function () {
        InfisicalLock::asSystem(fn () => null);

        expect(InfisicalLock::isSystemWrite())->toBeTrue();
    });

    expect(InfisicalLock::isSystemWrite())->toBeFalse();
});

it('does not lock one team because another team enabled infisical', function () {
    $locked = Team::factory()->create();
    $free = Team::factory()->create();
    InfisicalConnection::factory()->create(['team_id' => $locked->id, 'is_enabled' => true]);

    expect(fn () => SharedEnvironmentVariable::factory()->create([
        'team_id' => $free->id, 'type' => 'team',
    ]))->not->toThrow(InfisicalManagedVariableException::class);
});

it('leaves server scoped variables editable while the lock is armed', function () {
    InfisicalConnection::factory()->create([
        'team_id' => $this->infraTeam->id, 'is_enabled' => true,
    ]);

    expect(fn () => SharedEnvironmentVariable::factory()->create([
        'team_id' => $this->infraTeam->id,
        'type' => 'server',
        'server_id' => $this->server->id,
    ]))->not->toThrow(InfisicalManagedVariableException::class);
});

it('arms the lock within the same request when a connection is enabled', function () {
    $team = Team::factory()->create();
    $connection = InfisicalConnection::factory()->create([
        'team_id' => $team->id, 'is_enabled' => false,
    ]);

    SharedEnvironmentVariable::factory()->create(['team_id' => $team->id, 'type' => 'team']);

    $connection->update(['is_enabled' => true]);

    expect(fn () => SharedEnvironmentVariable::factory()->create([
        'team_id' => $team->id, 'type' => 'team',
    ]))->toThrow(InfisicalManagedVariableException::class);
});

it('rejects and allows two different teams in the same request', function () {
    $locked = Team::factory()->create();
    $free = Team::factory()->create();
    InfisicalConnection::factory()->create(['team_id' => $locked->id, 'is_enabled' => true]);

    expect(fn () => SharedEnvironmentVariable::factory()->create([
        'team_id' => $locked->id, 'type' => 'team',
    ]))->toThrow(InfisicalManagedVariableException::class);

    expect(fn () => SharedEnvironmentVariable::factory()->create([
        'team_id' => $free->id, 'type' => 'team',
    ]))->not->toThrow(InfisicalManagedVariableException::class);
});

it('tracks an infisical pull separately from a locally generated write', function () {
    InfisicalLock::asInfisicalPull(function () {
        expect(InfisicalLock::isSystemWrite())->toBeTrue()
            ->and(InfisicalLock::isInfisicalPull())->toBeTrue();
    });

    InfisicalLock::asSystem(function () {
        expect(InfisicalLock::isSystemWrite())->toBeTrue()
            ->and(InfisicalLock::isInfisicalPull())->toBeFalse();
    });

    expect(InfisicalLock::isInfisicalPull())->toBeFalse();
});

it('rejects a human environment variable write for every owner family', function (string $class) {
    $resource = lockedOwner($class);

    expect(fn () => EnvironmentVariable::create([
        'key' => 'TYPED_BY_HAND',
        'value' => 'x',
        'resourceable_type' => $resource::class,
        'resourceable_id' => $resource->id,
    ]))->toThrow(InfisicalManagedVariableException::class);
})->with([
    Application::class,
    Service::class,
    ServiceApplication::class,
    StandalonePostgresql::class,
    StandaloneMysql::class,
    StandaloneMariadb::class,
    StandaloneMongodb::class,
    StandaloneRedis::class,
    StandaloneKeydb::class,
    StandaloneDragonfly::class,
    StandaloneClickhouse::class,
]);

it('rejects a human environment variable delete for every owner family', function (string $class) {
    $resource = lockedOwner($class);

    $variable = InfisicalLock::asSystem(fn () => EnvironmentVariable::create([
        'key' => 'WRITTEN_BY_COOLIFY',
        'value' => 'x',
        'resourceable_type' => $resource::class,
        'resourceable_id' => $resource->id,
    ]));

    expect(fn () => $variable->delete())->toThrow(InfisicalManagedVariableException::class);
})->with([
    Application::class,
    Service::class,
    ServiceApplication::class,
    StandalonePostgresql::class,
    StandaloneMysql::class,
    StandaloneMariadb::class,
    StandaloneMongodb::class,
    StandaloneRedis::class,
    StandaloneKeydb::class,
    StandaloneDragonfly::class,
    StandaloneClickhouse::class,
]);

it('allows a system environment variable write for every owner family', function (string $class) {
    $resource = lockedOwner($class);

    expect(fn () => InfisicalLock::asSystem(fn () => EnvironmentVariable::create([
        'key' => 'WRITTEN_BY_COOLIFY',
        'value' => 'x',
        'resourceable_type' => $resource::class,
        'resourceable_id' => $resource->id,
    ])))->not->toThrow(InfisicalManagedVariableException::class);
})->with([
    Application::class,
    Service::class,
    ServiceApplication::class,
    StandalonePostgresql::class,
    StandaloneMysql::class,
    StandaloneMariadb::class,
    StandaloneMongodb::class,
    StandaloneRedis::class,
    StandaloneKeydb::class,
    StandaloneDragonfly::class,
    StandaloneClickhouse::class,
]);

it('allows an environment variable write on an unarmed team', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockerfile',
    ]);

    expect(fn () => EnvironmentVariable::create([
        'key' => 'TYPED_BY_HAND',
        'value' => 'x',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]))->not->toThrow(InfisicalManagedVariableException::class);
});
