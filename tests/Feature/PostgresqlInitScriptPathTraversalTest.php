<?php

use App\Actions\Database\StartPostgresql;
use App\Livewire\Project\Database\Postgresql\General as PostgresqlGeneral;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\DatabaseStartCommandExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    Process::fake();
    Queue::fake();

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

test('a stored init script filename with path traversal is written inside the initdb directory', function () {
    $executor = new class
    {
        public array $commands = [];

        public function execute(array $commands, $database, Activity $activity): Activity
        {
            $this->commands = $commands;

            return $activity;
        }
    };
    app()->instance(DatabaseStartCommandExecutor::class, $executor);

    $database = create_standalone_postgresql($this->environment->id, $this->destination, [
        'init_scripts' => [['index' => 0, 'filename' => '../../../../../etc/cron.d/pwn', 'content' => 'x']],
    ]);

    StartPostgresql::run($database->fresh(), new Activity);

    $initDir = database_configuration_dir().'/'.$database->uuid.'/docker-entrypoint-initdb.d';
    $teeCommands = collect($executor->commands)->filter(fn ($command) => is_string($command) && str_contains($command, '| tee ') && str_contains($command, 'docker-entrypoint-initdb.d'));

    expect($teeCommands)->toHaveCount(1)
        ->and($teeCommands->first())->toEndWith('| tee '.escapeshellarg($initDir.'/pwn').' > /dev/null')
        ->and(implode("\n", array_filter($executor->commands, 'is_string')))->not->toContain('etc/cron.d');
});

test('the init script list cannot be replaced from the browser', function () {
    $database = create_standalone_postgresql($this->environment->id, $this->destination);
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    expect(fn () => Livewire::test(PostgresqlGeneral::class, ['database' => $database->fresh()])
        ->set('initScripts', [['index' => 0, 'filename' => '../../etc/cron.d/pwn', 'content' => 'x']]))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect($database->fresh()->init_scripts)->toBeEmpty();
});

test('saving an init script with an unknown original filename validates the new filename', function () {
    $database = create_standalone_postgresql($this->environment->id, $this->destination);
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(PostgresqlGeneral::class, ['database' => $database->fresh()])
        ->call('save_init_script', ['index' => 0, 'filename' => '../../etc/cron.d/pwn', 'content' => 'x'], 'missing.sql')
        ->assertDispatched('error');

    expect($database->fresh()->init_scripts)->toBeEmpty();
});

test('saving an existing init script with a safe filename still works', function () {
    $database = create_standalone_postgresql($this->environment->id, $this->destination, [
        'init_scripts' => [['index' => 0, 'filename' => 'init.sql', 'content' => 'select 1;']],
    ]);
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(PostgresqlGeneral::class, ['database' => $database->fresh()])
        ->call('save_init_script', ['index' => 0, 'filename' => 'init.sql', 'content' => 'select 2;'], 'init.sql')
        ->assertDispatched('success');

    expect($database->fresh()->init_scripts)->toBe([['index' => 0, 'filename' => 'init.sql', 'content' => 'select 2;']]);
});
