<?php

use App\Livewire\Project\Database\Clickhouse\General as ClickhouseGeneral;
use App\Livewire\Project\Database\Clickhouse\StatusInfo as ClickhouseStatusInfo;
use App\Livewire\Project\Database\Dragonfly\General as DragonflyGeneral;
use App\Livewire\Project\Database\Dragonfly\StatusInfo as DragonflyStatusInfo;
use App\Livewire\Project\Database\Keydb\General as KeydbGeneral;
use App\Livewire\Project\Database\Keydb\StatusInfo as KeydbStatusInfo;
use App\Livewire\Project\Database\Mariadb\General as MariadbGeneral;
use App\Livewire\Project\Database\Mariadb\StatusInfo as MariadbStatusInfo;
use App\Livewire\Project\Database\Mongodb\General as MongodbGeneral;
use App\Livewire\Project\Database\Mongodb\StatusInfo as MongodbStatusInfo;
use App\Livewire\Project\Database\Mysql\General as MysqlGeneral;
use App\Livewire\Project\Database\Mysql\StatusInfo as MysqlStatusInfo;
use App\Livewire\Project\Database\Postgresql\General as PostgresqlGeneral;
use App\Livewire\Project\Database\Postgresql\StatusInfo as PostgresqlStatusInfo;
use App\Livewire\Project\Database\Redis\General as RedisGeneral;
use App\Livewire\Project\Database\Redis\StatusInfo as RedisStatusInfo;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->team->members()->attach($this->member->id, ['role' => 'member']);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

dataset('database-credential-components', [
    'postgresql' => ['create_standalone_postgresql', PostgresqlGeneral::class, PostgresqlStatusInfo::class, ['postgres_password' => 'stored-db-credential'], ['postgresPassword']],
    'mysql' => ['create_standalone_mysql', MysqlGeneral::class, MysqlStatusInfo::class, ['mysql_root_password' => 'stored-db-credential', 'mysql_password' => 'stored-db-credential'], ['mysqlRootPassword', 'mysqlPassword']],
    'mariadb' => ['create_standalone_mariadb', MariadbGeneral::class, MariadbStatusInfo::class, ['mariadb_root_password' => 'stored-db-credential', 'mariadb_password' => 'stored-db-credential'], ['mariadbRootPassword', 'mariadbPassword']],
    'mongodb' => ['create_standalone_mongodb', MongodbGeneral::class, MongodbStatusInfo::class, ['mongo_initdb_root_password' => 'stored-db-credential'], ['mongoInitdbRootPassword']],
    'redis' => ['create_standalone_redis', RedisGeneral::class, RedisStatusInfo::class, ['redis_password' => 'stored-db-credential'], ['redisPassword']],
    'keydb' => ['create_standalone_keydb', KeydbGeneral::class, KeydbStatusInfo::class, ['keydb_password' => 'stored-db-credential'], ['keydbPassword']],
    'dragonfly' => ['create_standalone_dragonfly', DragonflyGeneral::class, DragonflyStatusInfo::class, ['dragonfly_password' => 'stored-db-credential'], ['dragonflyPassword']],
    'clickhouse' => ['create_standalone_clickhouse', ClickhouseGeneral::class, ClickhouseStatusInfo::class, ['clickhouse_admin_password' => 'stored-db-credential'], ['clickhouseAdminPassword']],
]);

function createDatabaseWithCredential(string $createDatabase, array $credentials): mixed
{
    return $createDatabase(test()->environment->id, test()->destination, $credentials)->fresh();
}

it('keeps database credentials out of member state after a refresh', function (string $createDatabase, string $generalClass, string $statusInfoClass, array $credentials, array $passwordProperties) {
    $database = createDatabaseWithCredential($createDatabase, $credentials);

    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $general = Livewire::test($generalClass, ['database' => $database]);
    if (method_exists($generalClass, 'refresh')) {
        $general->call('refresh');
    }
    foreach ($passwordProperties as $property) {
        $general->assertSet($property, '');
    }
    expect(json_encode($general->snapshot))->not->toContain('stored-db-credential');

    $statusInfo = Livewire::test($statusInfoClass, ['database' => $database])
        ->set('isPasswordHiddenForMember', false)
        ->call('refresh')
        ->assertSet('dbUrl', null)
        ->assertSet('dbUrlPublic', null);
    expect(json_encode($statusInfo->snapshot))->not->toContain('stored-db-credential');
})->with('database-credential-components');

it('keeps database credentials available to an owner', function (string $createDatabase, string $generalClass, string $statusInfoClass, array $credentials, array $passwordProperties) {
    $database = createDatabaseWithCredential($createDatabase, $credentials);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $general = Livewire::test($generalClass, ['database' => $database]);
    foreach ($passwordProperties as $property) {
        $general->assertSet($property, 'stored-db-credential');
    }

    Livewire::test($statusInfoClass, ['database' => $database])
        ->call('refresh')
        ->assertSet('dbUrl', $database->fresh()->internal_db_url);
})->with('database-credential-components');
