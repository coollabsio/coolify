<?php

use App\Livewire\Project\Database\Dragonfly\StatusInfo as DragonflyStatusInfo;
use App\Livewire\Project\Database\Keydb\General as KeydbGeneral;
use App\Livewire\Project\Database\Keydb\StatusInfo as KeydbStatusInfo;
use App\Livewire\Project\Database\Mariadb\General as MariadbGeneral;
use App\Livewire\Project\Database\Mongodb\General as MongodbGeneral;
use App\Livewire\Project\Database\Mysql\General as MysqlGeneral;
use App\Livewire\Project\Database\Postgresql\General as PostgresqlGeneral;
use App\Livewire\Project\Database\Redis\General as RedisGeneral;
use App\Livewire\Project\Database\Redis\StatusInfo as RedisStatusInfo;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

dataset('database-config-components', [
    'postgresql' => ['create_standalone_postgresql', PostgresqlGeneral::class, [
        'postgres_conf' => 'password_encryption = secret-config-value',
        'init_scripts' => [['index' => 0, 'filename' => 'init.sql', 'content' => "CREATE USER app PASSWORD 'secret-config-value';"]],
    ], ['postgresConf', 'initScripts']],
    'mysql' => ['create_standalone_mysql', MysqlGeneral::class, ['mysql_conf' => 'password=secret-config-value'], ['mysqlConf']],
    'mariadb' => ['create_standalone_mariadb', MariadbGeneral::class, ['mariadb_conf' => 'password=secret-config-value'], ['mariadbConf']],
    'mongodb' => ['create_standalone_mongodb', MongodbGeneral::class, ['mongo_conf' => 'keyFile: secret-config-value'], ['mongoConf']],
    'redis' => ['create_standalone_redis', RedisGeneral::class, ['redis_conf' => 'requirepass secret-config-value'], ['redisConf']],
    'keydb' => ['create_standalone_keydb', KeydbGeneral::class, ['keydb_conf' => 'requirepass secret-config-value'], ['keydbConf']],
]);

function createDatabaseWithConfig(string $createDatabase, array $config): mixed
{
    $database = $createDatabase(test()->environment->id, test()->destination);
    $database->forceFill($config)->save();

    return $database->fresh();
}

function lockRedisPassword(StandaloneRedis $redis, ?string $value = null): void
{
    $redis->runtime_environment_variables()->where('key', 'REDIS_PASSWORD')->firstOrFail()
        ->forceFill(array_filter(['is_shown_once' => true, 'value' => $value], fn ($attribute) => $attribute !== null))
        ->save();
}

it('keeps custom configs and init scripts out of member state and html', function (string $createDatabase, string $generalClass, array $config, array $properties) {
    $database = createDatabaseWithConfig($createDatabase, $config);

    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $general = Livewire::test($generalClass, ['database' => $database]);
    if (method_exists($generalClass, 'refresh')) {
        $general->call('refresh');
    }
    foreach ($properties as $property) {
        $general->assertSet($property, null);
    }
    expect(json_encode($general->snapshot))->not->toContain('secret-config-value')
        ->and($general->html())->not->toContain('secret-config-value')
        ->and($general->html())->toContain('Hidden (only admins can view)');
})->with('database-config-components');

it('keeps custom configs out of a member who switched the session to a team they own', function (string $createDatabase, string $generalClass, array $config, array $properties) {
    $database = createDatabaseWithConfig($createDatabase, $config);
    $ownTeam = Team::factory()->create();
    $ownTeam->members()->attach($this->member->id, ['role' => 'owner']);

    $this->actingAs($this->member);
    session(['currentTeam' => $ownTeam]);

    $general = Livewire::test($generalClass, ['database' => $database]);
    foreach ($properties as $property) {
        $general->assertSet($property, null);
    }
    expect(json_encode($general->snapshot))->not->toContain('secret-config-value')
        ->and($general->html())->not->toContain('secret-config-value');
})->with('database-config-components');

it('keeps custom configs and init scripts available to an owner', function (string $createDatabase, string $generalClass, array $config, array $properties) {
    $database = createDatabaseWithConfig($createDatabase, $config);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $general = Livewire::test($generalClass, ['database' => $database]);
    foreach (array_values($config) as $index => $value) {
        $general->assertSet($properties[$index], $value);
    }
})->with('database-config-components');

it('does not let a member save custom configs', function () {
    $database = createDatabaseWithConfig('create_standalone_redis', ['redis_conf' => 'requirepass secret-config-value']);

    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(RedisGeneral::class, ['database' => $database])
        ->set('redisConf', 'requirepass changed')
        ->call('submit');

    expect($database->fresh()->redis_conf)->toBe('requirepass secret-config-value');
});

it('does not show a locked redis password to an owner in the form or the connection urls', function () {
    $redis = create_standalone_redis($this->environment->id, $this->destination, ['redis_password' => 'locked-redis-secret']);
    lockRedisPassword($redis);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $general = Livewire::test(RedisGeneral::class, ['database' => $redis->fresh()])
        ->assertSet('redisPassword', null)
        ->assertSet('isPasswordLocked', true);
    expect(json_encode($general->snapshot))->not->toContain('locked-redis-secret')
        ->and($general->html())->not->toContain('locked-redis-secret')
        ->and($general->html())->toContain('Locked (shown once)');

    $statusInfo = Livewire::test(RedisStatusInfo::class, ['database' => $redis->fresh()])
        ->assertSet('dbUrl', null)
        ->assertSet('dbUrlPublic', null);
    expect(json_encode($statusInfo->snapshot))->not->toContain('locked-redis-secret')
        ->and($statusInfo->html())->not->toContain('locked-redis-secret')
        ->and($statusInfo->html())->toContain('Locked (shown once)');
});

it('does not show a locked shared variable that the redis password reads in the connection urls', function () {
    $redis = create_standalone_redis($this->environment->id, $this->destination);
    SharedEnvironmentVariable::create([
        'key' => 'REDIS_SECRET',
        'value' => 'locked-shared-secret',
        'type' => 'team',
        'team_id' => $this->team->id,
        'is_shown_once' => true,
    ]);
    $redis->runtime_environment_variables()->where('key', 'REDIS_PASSWORD')->firstOrFail()
        ->forceFill(['value' => '{{team.REDIS_SECRET}}'])->save();

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $statusInfo = Livewire::test(RedisStatusInfo::class, ['database' => $redis->fresh()])
        ->assertSet('dbUrl', null);
    expect(json_encode($statusInfo->snapshot))->not->toContain('locked-shared-secret');
});

it('does not show a locked REDIS_PASSWORD override in the keydb and dragonfly connection urls', function (string $createDatabase, string $statusInfoClass) {
    $database = $createDatabase($this->environment->id, $this->destination);
    $database->forceFill(['legacy_password_quoting' => false])->save();
    EnvironmentVariable::create([
        'key' => 'REDIS_PASSWORD',
        'value' => 'locked-override-secret',
        'is_shown_once' => true,
        'resourceable_type' => $database::class,
        'resourceable_id' => $database->id,
    ]);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $statusInfo = Livewire::test($statusInfoClass, ['database' => $database->fresh()])
        ->assertSet('dbUrl', null);
    expect(json_encode($statusInfo->snapshot))->not->toContain('locked-override-secret')
        ->and($statusInfo->html())->toContain('Locked (shown once)');
})->with([
    'keydb' => ['create_standalone_keydb', KeydbStatusInfo::class],
    'dragonfly' => ['create_standalone_dragonfly', DragonflyStatusInfo::class],
]);

it('keeps a locked redis password when an owner saves the form', function () {
    $redis = create_standalone_redis($this->environment->id, $this->destination, ['redis_password' => 'locked-redis-secret']);
    lockRedisPassword($redis);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(RedisGeneral::class, ['database' => $redis->fresh()])
        ->set('name', 'renamed-redis')
        ->call('submit')
        ->assertHasNoErrors();

    $redis = $redis->fresh();
    expect($redis->name)->toBe('renamed-redis')
        ->and(EnvironmentVariable::query()->where('resourceable_type', StandaloneRedis::class)
            ->where('resourceable_id', $redis->id)->where('key', 'REDIS_PASSWORD')->firstOrFail()->value)
        ->toBe('locked-redis-secret');
});

it('redacts a locked redis password and its connection urls in the api', function () {
    $redis = create_standalone_redis($this->environment->id, $this->destination, ['redis_password' => 'locked-redis-secret']);
    lockRedisPassword($redis);

    session(['currentTeam' => $this->team]);
    $token = $this->owner->createToken('locked-test', ['read', 'read:sensitive']);
    DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update(['team_id' => $this->team->id]);
    $headers = ['Authorization' => 'Bearer '.$token->plainTextToken];

    $single = $this->withHeaders($headers)->getJson("/api/v1/databases/{$redis->uuid}")->assertOk();
    expect($single->getContent())->not->toContain('locked-redis-secret')
        ->and($single->json('internal_db_url'))->toBeNull();

    $list = $this->withHeaders($headers)->getJson('/api/v1/databases')->assertOk();
    expect($list->getContent())->not->toContain('locked-redis-secret');
});

it('still returns the connection url of an unlocked redis password in the api', function () {
    $redis = create_standalone_redis($this->environment->id, $this->destination, ['redis_password' => 'visible-redis-secret']);

    session(['currentTeam' => $this->team]);
    $token = $this->owner->createToken('unlocked-test', ['read', 'read:sensitive']);
    DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update(['team_id' => $this->team->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken])
        ->getJson("/api/v1/databases/{$redis->uuid}")
        ->assertOk()
        ->assertJsonPath('internal_db_url', "redis://default:visible-redis-secret@{$redis->uuid}:6379/0");
});
