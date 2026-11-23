<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $this->database = create_standalone_postgresql($environment->id, $destination);
    $this->backup = ScheduledDatabaseBackup::create([
        'team_id' => $team->id,
        'frequency' => '0 0 * * *',
        'database_id' => $this->database->id,
        'database_type' => $this->database->getMorphClass(),
    ]);
    $this->execution = ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $this->backup->id,
        'status' => 'success',
        'filename' => '/data/coolify/backups/api-delete.dmp',
    ]);

    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'test-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['root'],
        'team_id' => $team->id,
    ]);
    $this->bearer = $token->getKey().'|'.$plainTextToken;

    $this->remoteScripts = collect();
    Process::fake(function ($process) {
        $this->remoteScripts->push(remoteProcessScript($process));

        return Process::result();
    });
});

dataset('backup delete paths', [
    'delete schedule' => [fn () => "/api/v1/databases/{$this->database->uuid}/backups/{$this->backup->uuid}"],
    'delete execution' => [fn () => "/api/v1/databases/{$this->database->uuid}/backups/{$this->backup->uuid}/executions/{$this->execution->uuid}"],
]);

it('deletes the execution without the local file when the server is not reachable', function (Closure $path) {
    $this->server->settings()->update(['is_reachable' => false, 'is_usable' => false]);
    Process::fake(function ($process) {
        $this->remoteScripts->push(remoteProcessScript($process));

        return Process::result(errorOutput: 'ssh: connect to host: Connection timed out', exitCode: 255);
    });

    $this->withToken($this->bearer)->deleteJson($path->call($this))->assertOk();

    expect($this->execution->fresh())->toBeNull()
        ->and($this->remoteScripts)->toBeEmpty();
})->with('backup delete paths');

it('deletes the local file when the server is reachable', function (Closure $path) {
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);

    $this->withToken($this->bearer)->deleteJson($path->call($this))->assertOk();

    expect($this->execution->fresh())->toBeNull()
        ->and($this->remoteScripts->contains(fn (string $script) => str_contains($script, "rm -f '/data/coolify/backups/api-delete.dmp'")))->toBeTrue();
})->with('backup delete paths');
