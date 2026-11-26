<?php

use App\Jobs\DatabaseBackupJob;
use App\Livewire\Project\Database\Backup\ExecutionHistory;
use App\Livewire\Project\Database\ScheduledBackups;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->database = executionHistoryDatabase($this->team);
    $this->backup = executionHistoryBackup($this->database);
    $this->execution = executionHistoryExecution($this->backup, 'success');

    $this->remoteScripts = collect();
    Process::fake(function ($process) {
        $this->remoteScripts->push(remoteProcessScript($process));

        return Process::result();
    });
});

function executionHistoryDatabase(Team $team): StandalonePostgresql
{
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true, 'server_timezone' => 'Europe/Budapest']);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);

    return create_standalone_postgresql($environment->id, $destination, ['status' => 'running:healthy']);
}

function executionHistoryBackup(StandalonePostgresql $database): ScheduledDatabaseBackup
{
    return ScheduledDatabaseBackup::create([
        'team_id' => $database->team()->id,
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
    ]);
}

function executionHistoryExecution(ScheduledDatabaseBackup $backup, string $status, array $attributes = []): ScheduledDatabaseBackupExecution
{
    return ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $backup->id,
        'status' => $status,
        'database_name' => 'postgres',
        'filename' => '/data/coolify/backups/'.fake()->uuid().'.dmp',
        'local_storage_deleted' => false,
        ...$attributes,
    ]);
}

function executionHistoryMember(Team $team): User
{
    $member = User::factory()->create();
    $team->members()->attach($member->id, ['role' => 'member']);

    return $member;
}

it('deletes one execution and its local file', function () {
    $other = executionHistoryExecution($this->backup, 'success');

    Livewire::test(ExecutionHistory::class, ['database' => $this->database])
        ->call('deleteExecution', $this->execution->uuid, 'password')
        ->assertDispatched('success');

    expect($this->execution->fresh())->toBeNull()
        ->and($other->fresh())->not->toBeNull()
        ->and($this->remoteScripts->contains(fn (string $script) => str_contains($script, "rm -f '{$this->execution->filename}'")))->toBeTrue();
});

it('keeps the execution when the password is wrong', function () {
    $this->user->forceFill(['password' => bcrypt('correct-password')])->save();

    Livewire::test(ExecutionHistory::class, ['database' => $this->database])
        ->call('deleteExecution', $this->execution->uuid, 'wrong-password');

    expect($this->execution->fresh())->not->toBeNull()
        ->and($this->remoteScripts)->toBeEmpty();
});

it('does not open, download, or delete an execution of another database', function (string $owner) {
    $database = $owner === 'team'
        ? create_standalone_postgresql($this->database->environment_id, $this->database->destination)
        : executionHistoryDatabase(Team::factory()->create());
    $foreign = executionHistoryExecution(executionHistoryBackup($database), 'failed');

    $component = fn () => Livewire::test(ExecutionHistory::class, ['database' => $this->database]);
    $component()->call('openExecution', $foreign->uuid)->assertNotFound();
    $component()->call('downloadExecution', $foreign->uuid)->assertNotFound();
    $component()->call('deleteExecution', $foreign->uuid, 'password')->assertNotFound();
    $component()->call('cleanupFailed');

    expect($foreign->fresh())->not->toBeNull()
        ->and($this->remoteScripts)->toBeEmpty();
})->with(['same team' => 'team', 'other team' => 'other-team']);

it('cleans up the failed executions of the database', function () {
    $failed = executionHistoryExecution($this->backup, 'failed');
    $otherSchedule = executionHistoryExecution(executionHistoryBackup($this->database), 'failed');

    Livewire::test(ExecutionHistory::class, ['database' => $this->database])
        ->call('cleanupFailed')
        ->assertDispatched('success');

    expect($failed->fresh())->toBeNull()
        ->and($otherSchedule->fresh())->toBeNull()
        ->and($this->execution->fresh())->not->toBeNull();
});

it('cleans up the executions whose local file is deleted', function () {
    $deleted = executionHistoryExecution($this->backup, 'success', ['local_storage_deleted' => true]);

    Livewire::test(ExecutionHistory::class, ['database' => $this->database])
        ->call('cleanupDeleted')
        ->assertDispatched('success');

    expect($deleted->fresh())->toBeNull()
        ->and($this->execution->fresh())->not->toBeNull();
});

it('forbids a member to delete or clean up executions', function (string $method, array $arguments) {
    $failed = executionHistoryExecution($this->backup, 'failed');
    $this->actingAs(executionHistoryMember($this->team));

    Livewire::test(ExecutionHistory::class, ['database' => $this->database])
        ->call($method, ...array_map(fn ($argument) => $argument === ':uuid' ? $this->execution->uuid : $argument, $arguments))
        ->assertForbidden();

    expect($this->execution->fresh())->not->toBeNull()
        ->and($failed->fresh())->not->toBeNull();
})->with([
    'delete' => ['deleteExecution', [':uuid', 'password']],
    'clean up failed' => ['cleanupFailed', []],
    'clean up deleted' => ['cleanupDeleted', []],
]);

it('shows the finish time of an execution in the server timezone', function () {
    $this->travelTo(Carbon::parse('2026-10-16 14:00:00', 'UTC'));
    executionHistoryExecution($this->backup, 'success', ['finished_at' => Carbon::parse('2026-10-16 14:30:05', 'UTC')]);
    $this->travelTo(Carbon::parse('2026-10-16 18:00:00', 'UTC'));

    Livewire::test(ExecutionHistory::class, ['database' => $this->database])
        ->assertSee('2026-10-16 16:30:05 CEST')
        ->assertSee('Started: 2026-10-16 16:00:00 CEST', false)
        ->assertSee('3 hours ago');
});

it('queues a backup from the schedule list and records an audit event', function () {
    Queue::fake([DatabaseBackupJob::class]);

    Livewire::test(ScheduledBackups::class, ['database' => $this->database])
        ->call('backupNow', $this->backup->uuid)
        ->assertDispatched('success');

    Queue::assertPushed(DatabaseBackupJob::class);
    expect(AuditEvent::query()->where('event', 'ui.database.backup_started')->exists())->toBeTrue();
});

it('does not queue a backup when the database stopped after the page loaded', function () {
    Queue::fake([DatabaseBackupJob::class]);
    $component = Livewire::test(ScheduledBackups::class, ['database' => $this->database]);
    StandalonePostgresql::whereKey($this->database->id)->update(['status' => 'exited']);

    $component->call('backupNow', $this->backup->uuid)->assertDispatched('error');

    Queue::assertNotPushed(DatabaseBackupJob::class);
});

it('forbids a member to queue a backup', function () {
    Queue::fake([DatabaseBackupJob::class]);
    $this->actingAs(executionHistoryMember($this->team));

    Livewire::test(ScheduledBackups::class, ['database' => $this->database])
        ->call('backupNow', $this->backup->uuid)
        ->assertForbidden();

    Queue::assertNotPushed(DatabaseBackupJob::class);
});

it('does not queue a backup of a schedule of another database', function () {
    Queue::fake([DatabaseBackupJob::class]);
    $foreign = executionHistoryBackup(executionHistoryDatabase(Team::factory()->create()));

    Livewire::test(ScheduledBackups::class, ['database' => $this->database])
        ->call('backupNow', $foreign->uuid)
        ->assertNotFound();

    Queue::assertNotPushed(DatabaseBackupJob::class);
});

it('loads the latest execution and the execution count of all schedules at once', function () {
    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ScheduledBackups::class, ['database' => $this->database->fresh()]);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $countQueries();
    $oneSchedule = $countQueries();
    foreach (range(1, 3) as $ignored) {
        executionHistoryExecution(executionHistoryBackup($this->database), 'success');
    }

    expect($countQueries())->toBe($oneSchedule);
});

it('deletes the executions with their schedule and removes orphaned executions', function () {
    $migration = require database_path('migrations/2026_11_15_120000_cascade_database_backup_executions_on_schedule_delete.php');
    DB::statement('PRAGMA defer_foreign_keys = ON');
    $orphan = executionHistoryExecution($this->backup, 'success', ['scheduled_database_backup_id' => 999999]);
    $migration->up();

    $this->backup->delete();

    expect($orphan->fresh())->toBeNull()
        ->and($this->execution->fresh())->toBeNull();
});
