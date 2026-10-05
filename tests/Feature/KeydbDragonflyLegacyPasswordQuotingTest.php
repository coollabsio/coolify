<?php

use App\Actions\Database\StartDragonfly;
use App\Actions\Database\StartKeydb;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
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

/**
 * @return array{command: string, healthcheck: list<string>}
 */
function legacyPasswordQuotingStartedService(object $executor, string $uuid): array
{
    foreach ($executor->commands as $command) {
        if (preg_match("#^echo '([^']+)' \| base64 -d \| tee \S+/docker-compose\.yml#", $command, $matches)) {
            $service = Yaml::parse(base64_decode($matches[1]))['services'][$uuid];

            return ['command' => $service['command'], 'healthcheck' => $service['healthcheck']['test']];
        }
    }

    throw new RuntimeException('No docker-compose.yml was written.');
}

dataset('legacy-password-quoting-engines', [
    'keydb' => ['create_standalone_keydb', StartKeydb::class, 'keydb_password', 'keydb-server --requirepass %s --appendonly yes'],
    'dragonfly' => ['create_standalone_dragonfly', StartDragonfly::class, 'dragonfly_password', 'dragonfly --requirepass %s'],
]);

it('keeps the unquoted v4.3.23 start command for a database created before the upgrade', function (string $create, string $start, string $passwordColumn, string $commandFormat) {
    $database = $create($this->environment->id, $this->destination, [$passwordColumn => 'ab\c']);
    DB::table($database->getTable())->where('id', $database->id)->update(['legacy_password_quoting' => true]);

    $start::run($database->fresh(), new Activity);

    expect(legacyPasswordQuotingStartedService($this->executor, $database->uuid)['command'])
        ->toBe(sprintf($commandFormat, 'ab\c'));
})->with('legacy-password-quoting-engines');

it('quotes the exact stored password for a database created after the upgrade', function (string $create, string $start, string $passwordColumn, string $commandFormat) {
    $database = $create($this->environment->id, $this->destination, [$passwordColumn => 'ab\c']);

    $start::run($database->fresh(), new Activity);

    $service = legacyPasswordQuotingStartedService($this->executor, $database->uuid);
    expect($database->fresh()->legacy_password_quoting)->toBeFalse()
        ->and($service['command'])->toBe(sprintf($commandFormat, "'ab\\c'"))
        ->and($service['healthcheck'][3])->toBe('ab\c');
})->with('legacy-password-quoting-engines');

it('quotes legacy passwords that v4.3.23 passed unchanged or could not start with', function (string $create, string $start, string $passwordColumn, string $commandFormat) {
    $database = $create($this->environment->id, $this->destination, [$passwordColumn => 'a(b)c']);
    DB::table($database->getTable())->where('id', $database->id)->update(['legacy_password_quoting' => true]);

    $start::run($database->fresh(), new Activity);

    expect(legacyPasswordQuotingStartedService($this->executor, $database->uuid)['command'])
        ->toBe(sprintf($commandFormat, "'a(b)c'"));
})->with('legacy-password-quoting-engines');

it('uses the exact quoting after the password of a legacy database changes', function (string $create, string $start, string $passwordColumn, string $commandFormat) {
    $database = $create($this->environment->id, $this->destination, [$passwordColumn => 'ab\c']);
    DB::table($database->getTable())->where('id', $database->id)->update(['legacy_password_quoting' => true]);

    $database = $database->fresh();
    $database->update(['name' => 'renamed']);
    expect($database->fresh()->legacy_password_quoting)->toBeTrue();

    $database->update([$passwordColumn => 'new\pass']);
    $start::run($database->fresh(), new Activity);

    expect($database->fresh()->legacy_password_quoting)->toBeFalse()
        ->and(legacyPasswordQuotingStartedService($this->executor, $database->uuid)['command'])
        ->toBe(sprintf($commandFormat, "'new\\pass'"));
})->with('legacy-password-quoting-engines');

it('quotes a remote secret password of a legacy database exactly', function (string $create, string $start, string $passwordColumn, string $commandFormat) {
    Http::fake(['https://api.doppler.com/*' => Http::response(['REDIS_PASSWORD' => 'ab\\c'])]);
    $token = IntegrationToken::query()->create([
        'team_id' => $this->environment->project->team_id,
        'provider' => 'doppler',
        'name' => 'Doppler',
        'token' => 'the-secret-token',
        'capabilities' => ['secrets'],
    ]);
    $database = $create($this->environment->id, $this->destination, [$passwordColumn => 'stored']);
    DB::table($database->getTable())->where('id', $database->id)->update(['legacy_password_quoting' => true]);
    $database->secretManagerLink()->create(['integration_token_id' => $token->id]);
    $database->runtime_environment_variables()->create(['key' => 'REDIS_PASSWORD', 'value' => '{{vault.REDIS_PASSWORD}}']);

    $start::run($database->fresh(), new Activity);

    expect(legacyPasswordQuotingStartedService($this->executor, $database->uuid)['command'])
        ->toBe(sprintf($commandFormat, "'ab\\c'"));
})->with('legacy-password-quoting-engines');

it('marks only databases that exist before the migration as legacy', function () {
    $existingKeydb = create_standalone_keydb($this->environment->id, $this->destination);
    $existingDragonfly = create_standalone_dragonfly($this->environment->id, $this->destination);

    $migration = require database_path('migrations/2026_10_02_120000_add_legacy_password_quoting_to_keydb_and_dragonfly.php');
    $migration->down();
    expect(Schema::hasColumn('standalone_keydbs', 'legacy_password_quoting'))->toBeFalse();
    $migration->up();

    $newKeydb = create_standalone_keydb($this->environment->id, $this->destination);
    $newDragonfly = create_standalone_dragonfly($this->environment->id, $this->destination);

    expect($existingKeydb->fresh()->legacy_password_quoting)->toBeTrue()
        ->and($existingDragonfly->fresh()->legacy_password_quoting)->toBeTrue()
        ->and($newKeydb->fresh()->legacy_password_quoting)->toBeFalse()
        ->and($newDragonfly->fresh()->legacy_password_quoting)->toBeFalse();
});

it('keeps the exact v4.3.23 start command when Docker Compose split or cut the unquoted legacy password', function (string $create, string $start, string $passwordColumn, string $commandFormat, string $password) {
    $database = $create($this->environment->id, $this->destination, [$passwordColumn => $password]);
    DB::table($database->getTable())->where('id', $database->id)->update(['legacy_password_quoting' => true]);

    $start::run($database->fresh(), new Activity);

    expect(legacyPasswordQuotingStartedService($this->executor, $database->uuid)['command'])
        ->toBe(sprintf($commandFormat, $password));
})->with('legacy-password-quoting-engines')->with([
    'semicolon' => 'ab;cd',
    'space' => 'a b',
    'tab' => "a\tb",
    'pipe' => 'x|y',
    'ampersand' => 'p&q',
    'redirects' => 'a<b>',
]);

it('quotes a plain legacy password, which gives Docker Compose the same argument as v4.3.23', function (string $create, string $start, string $passwordColumn, string $commandFormat) {
    $database = $create($this->environment->id, $this->destination, [$passwordColumn => 'abc']);
    DB::table($database->getTable())->where('id', $database->id)->update(['legacy_password_quoting' => true]);

    $start::run($database->fresh(), new Activity);

    expect(legacyPasswordQuotingStartedService($this->executor, $database->uuid)['command'])
        ->toBe(sprintf($commandFormat, "'abc'"));
})->with('legacy-password-quoting-engines');

it('quotes shell control characters in the password of a database created after the upgrade', function (string $create, string $start, string $passwordColumn, string $commandFormat, string $password) {
    $database = $create($this->environment->id, $this->destination, [$passwordColumn => $password]);

    $start::run($database->fresh(), new Activity);

    expect(legacyPasswordQuotingStartedService($this->executor, $database->uuid)['command'])
        ->toBe(sprintf($commandFormat, escapeshellarg($password)));
})->with('legacy-password-quoting-engines')->with([
    'semicolon' => 'ab;cd',
    'space' => 'a b',
    'pipe' => 'x|y',
]);
