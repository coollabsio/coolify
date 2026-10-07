<?php

use App\Actions\Database\StartRedis;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Services\DatabaseStartCommandExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    Process::fake();
    Queue::fake();
    Server::flushIdentityMap();

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);

    SharedEnvironmentVariable::query()->create([
        'key' => 'RPW',
        'value' => 's3cret',
        'type' => 'environment',
        'environment_id' => $this->environment->id,
        'project_id' => $project->id,
        'team_id' => $team->id,
    ]);

    $this->executor = new class
    {
        public array $commands = [];

        public function execute(array $commands, $database, Activity $activity): Activity
        {
            $this->commands = $commands;

            return $activity;
        }
    };
    app()->instance(DatabaseStartCommandExecutor::class, $this->executor);
});

function redisLegacyQuotingDatabase(object $test, string $password, bool $legacy, array $otherData = []): StandaloneRedis
{
    $database = create_standalone_redis($test->environment->id, $test->destination, ['redis_password' => $password, 'image' => 'redis:7.2', ...$otherData]);
    if ($legacy) {
        DB::table($database->getTable())->where('id', $database->id)->update(['legacy_password_quoting' => true]);
    }

    return $database->fresh();
}

function redisLegacyQuotingStartCommand(object $executor, string $uuid): string
{
    foreach ($executor->commands as $command) {
        if (preg_match("#^echo '([^']+)' \| base64 -d \| tee \S+/docker-compose\.yml#", $command, $matches)) {
            return Yaml::parse(base64_decode($matches[1]))['services'][$uuid]['command'];
        }
    }

    throw new RuntimeException('No docker-compose.yml was written.');
}

it('keeps the v4.3.23 server password and URL of a legacy database with a shared REDIS_PASSWORD reference', function () {
    $database = redisLegacyQuotingDatabase($this, '{{environment.RPW}}', legacy: true, otherData: ['is_public' => true, 'public_port' => 16379]);

    StartRedis::run($database, new Activity);

    $database = $database->fresh();
    $encodedReference = rawurlencode('{{environment.RPW}}');
    expect(redisLegacyQuotingStartCommand($this->executor, $database->uuid))
        ->toBe("redis-server --requirepass '{{environment.RPW}}' --appendonly yes")
        ->and($database->internal_db_url)->toContain(":{$encodedReference}@")
        ->and($database->external_db_url)->toContain(":{$encodedReference}@")
        ->and($database->runtime_environment_variables()->where('key', 'REDIS_PASSWORD')->first()->value)->toBe('{{environment.RPW}}')
        ->and($database->legacy_password_quoting)->toBeTrue();
});

it('keeps the unquoted v4.3.23 argument of a legacy password that quoting would change', function (string $password) {
    $database = redisLegacyQuotingDatabase($this, $password, legacy: true);

    StartRedis::run($database, new Activity);

    expect(redisLegacyQuotingStartCommand($this->executor, $database->uuid))
        ->toBe("redis-server --requirepass {$password} --appendonly yes");
})->with([
    'backslash' => 'ab\c',
    'quote' => "a'b",
    'space' => 'a b',
    'semicolon' => 'ab;cd',
]);

it('starts a new database with the resolved shared REDIS_PASSWORD and shows it in the URLs', function () {
    $database = redisLegacyQuotingDatabase($this, '{{environment.RPW}}', legacy: false, otherData: ['is_public' => true, 'public_port' => 16379]);

    StartRedis::run($database, new Activity);

    $database = $database->fresh();
    expect(redisLegacyQuotingStartCommand($this->executor, $database->uuid))
        ->toBe("redis-server --requirepass 's3cret' --appendonly yes")
        ->and($database->internal_db_url)->toContain(':s3cret@')
        ->and($database->external_db_url)->toContain(':s3cret@')
        ->and($database->legacy_password_quoting)->toBeFalse();
});

it('quotes special characters of the password of a new database', function (string $password) {
    SharedEnvironmentVariable::query()->where('key', 'RPW')->firstOrFail()->update(['value' => $password]);
    $local = redisLegacyQuotingDatabase($this, $password, legacy: false);
    $shared = redisLegacyQuotingDatabase($this, '{{environment.RPW}}', legacy: false);

    StartRedis::run($local, new Activity);
    $localCommand = redisLegacyQuotingStartCommand($this->executor, $local->uuid);
    StartRedis::run($shared, new Activity);
    $sharedCommand = redisLegacyQuotingStartCommand($this->executor, $shared->uuid);

    $expected = 'redis-server --requirepass '.escapeshellarg($password).' --appendonly yes';
    expect($localCommand)->toBe($expected)
        ->and($sharedCommand)->toBe($expected)
        ->and($shared->fresh()->internal_db_url)->toContain(':'.rawurlencode($password).'@');
})->with([
    'quote and space' => "pa ss'w",
    'backslash' => 'ab\c',
    'double quote' => 'a"b',
]);

it('quotes the password next to a custom redis.conf without requirepass', function () {
    $database = redisLegacyQuotingDatabase($this, 'a b', legacy: false, otherData: ['redis_conf' => 'maxmemory 100mb']);

    StartRedis::run($database, new Activity);

    expect(redisLegacyQuotingStartCommand($this->executor, $database->uuid))
        ->toBe("redis-server /usr/local/etc/redis/redis.conf --requirepass 'a b'");
});

it('quotes a remote secret password of a legacy database exactly and shows the reference in the URL', function () {
    Http::fake(['https://api.doppler.com/*' => Http::response(['REDIS_PASSWORD' => 'ab\\c'])]);
    $token = IntegrationToken::query()->create([
        'team_id' => $this->environment->project->team_id,
        'provider' => 'doppler',
        'name' => 'Doppler',
        'token' => 'the-secret-token',
        'capabilities' => ['secrets'],
    ]);
    $database = redisLegacyQuotingDatabase($this, '{{vault.REDIS_PASSWORD}}', legacy: true);
    $database->secretManagerLink()->create(['integration_token_id' => $token->id]);

    expect($database->fresh()->internal_db_url)->toContain(':'.rawurlencode('{{vault.REDIS_PASSWORD}}').'@');

    StartRedis::run($database->fresh(), new Activity);

    expect(redisLegacyQuotingStartCommand($this->executor, $database->uuid))
        ->toBe("redis-server --requirepass 'ab\\c' --appendonly yes");
});

it('marks only Redis databases that exist before the migration as legacy', function () {
    $existing = create_standalone_redis($this->environment->id, $this->destination);

    $migration = require database_path('migrations/2026_10_05_204113_add_legacy_password_quoting_to_standalone_redis.php');
    $migration->down();
    expect(Schema::hasColumn('standalone_redis', 'legacy_password_quoting'))->toBeFalse();
    $migration->up();

    $new = create_standalone_redis($this->environment->id, $this->destination);

    expect($existing->fresh()->legacy_password_quoting)->toBeTrue()
        ->and($new->fresh()->legacy_password_quoting)->toBeFalse();
});
