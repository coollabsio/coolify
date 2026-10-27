<?php

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Jobs\CoolifyTask;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Services\DatabaseStartCommandExecutor;
use App\Services\DockerRegistryLogins;
use App\Support\ResourceStartActivity;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('constants.ssh.mux_enabled', false);
    InstanceSettings::forceCreate(['id' => 0]);
    Queue::fake();

    $this->team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
        'user' => 'root',
    ]);
});

function remoteProcessRedactionService(Team $team, bool $redactAll): Service
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'redact_all_env_values_in_logs' => $redactAll,
    ]);
    foreach (['VISIBLE_VALUE' => ['visible-service-value', false], 'LOCKED_VALUE' => ['locked-service-value', true]] as $key => [$value, $locked]) {
        EnvironmentVariable::query()->create([
            'key' => $key,
            'value' => $value,
            'resourceable_type' => $service->getMorphClass(),
            'resourceable_id' => $service->id,
            'is_preview' => false,
            'is_shown_once' => $locked,
        ]);
    }

    return $service;
}

function remoteProcessTimeout(): ProcessTimedOutException
{
    $process = new SymfonyProcess(['ssh', 'root@host', 'echo SECRET_COMMAND_VALUE']);
    $process->setTimeout(5);

    return new ProcessTimedOutException(new SymfonyProcessTimedOutException($process, SymfonyProcessTimedOutException::TYPE_GENERAL), new ProcessResult($process));
}

it('hides service environment values in remote process output', function (bool $redactAll, string $expected) {
    $service = remoteProcessRedactionService($this->team, $redactAll);
    Process::fake(fn () => Process::result(output: 'visible-service-value locked-service-value'));
    $activity = remote_process(['docker compose up -d'], $this->server, type_uuid: $service->uuid);

    (new RunRemoteProcess(Activity::query()->findOrFail($activity->id)))();

    $stored = Activity::query()->findOrFail($activity->id);
    expect(RunRemoteProcess::decodeOutput($stored))->toBe($expected)
        ->and($stored->getExtraProperty('stdout'))->toBe($expected);
})->with([
    'redact all values' => [true, REDACTED.' '.REDACTED."\n"],
    'redact locked values only' => [false, 'visible-service-value '.REDACTED."\n"],
]);

it('does not put the command of a timed out remote process in the error', function () {
    Process::fake(fn () => throw remoteProcessTimeout());
    $activity = remote_process(['echo SECRET_COMMAND_VALUE'], $this->server);

    $run = new RunRemoteProcess(Activity::query()->findOrFail($activity->id));

    expect(fn () => $run())->toThrow(fn (RuntimeException $exception) => expect($exception->getMessage())
        ->not->toContain('SECRET_COMMAND_VALUE')
        ->and($exception->getCode())->toBe(124)
        ->and($exception->getPrevious())->toBeNull());
});

it('does not put the command of a timed out registry login in the error', function () {
    Process::fake(fn () => throw remoteProcessTimeout());

    expect(fn () => DockerRegistryLogins::login($this->server, 'ghcr.io', 'user', 'SECRET_COMMAND_VALUE'))
        ->toThrow(fn (RuntimeException $exception) => expect($exception->getMessage())
            ->not->toContain('SECRET_COMMAND_VALUE')
            ->and($exception->getCode())->toBe(124));
});

it('stores a redacted error when a coolify task fails permanently', function () {
    $activity = remote_process(['echo ok'], $this->server);
    $task = new CoolifyTask(Activity::query()->findOrFail($activity->id), ignore_errors: false, call_event_on_finish: null, call_event_data: null);

    $task->failed(new RuntimeException('failed: password=task-secret-value'));

    expect(Activity::query()->findOrFail($activity->id)->getExtraProperty('error'))
        ->not->toContain('task-secret-value')
        ->toContain(REDACTED);
});

it('stores a redacted message when an activity is marked as failed', function () {
    $activity = remote_process(['echo ok'], $this->server);

    ResourceStartActivity::markFailed($activity, 'failed: password=mark-failed-secret');

    $stored = Activity::query()->findOrFail($activity->id);
    expect($stored->getExtraProperty('error'))->not->toContain('mark-failed-secret')->toContain(REDACTED)
        ->and($stored->description)->not->toContain('mark-failed-secret');
});

it('redacts a database start secret that is split across output chunks', function () {
    $database = new class extends Model
    {
        public function resolvedSecretManagerValuesForRedaction(): array
        {
            return ['split-secret-value'];
        }
    };
    $database->setRelation('destination', (object) ['server' => $this->server]);
    $activity = remote_process(['echo ok'], $this->server);
    // The process fake ends every output chunk with a newline, so the chunks are sent by hand.
    $invoked = Mockery::mock(InvokedProcess::class);
    Process::shouldReceive('timeout->idleTimeout->start')->andReturnUsing(function (string $command, Closure $output) use ($invoked) {
        $invoked->shouldReceive('wait')->andReturnUsing(function () use ($output) {
            foreach (['value is split-sec', "ret-value\nnext ", 'line split-secret-value'] as $chunk) {
                $output('out', $chunk);
            }

            return new FakeProcessResult;
        });

        return $invoked;
    });

    app(DatabaseStartCommandExecutor::class)->execute(['echo ok'], $database, $activity);

    expect(RunRemoteProcess::decodeOutput(Activity::query()->findOrFail($activity->id)))
        ->toBe('value is '.REDACTED."\nnext line ".REDACTED);
});

/**
 * Runs a remote process whose output arrives in the given chunks, like a real SSH stream.
 *
 * @param  list<array{0: string, 1: string}>  $chunks
 */
function runRemoteProcessWithChunks(Activity $activity, array $chunks, ?Throwable $failure = null): void
{
    // The process fake ends every output chunk with a newline, so the chunks are sent by hand.
    $invoked = Mockery::mock(InvokedProcess::class);
    $invoked->shouldReceive('id')->andReturn(123);
    Process::shouldReceive('timeout->start')->andReturnUsing(function (string $command, Closure $output) use ($invoked, $chunks, $failure) {
        $invoked->shouldReceive('wait')->andReturnUsing(function () use ($output, $chunks, $failure) {
            foreach ($chunks as [$type, $chunk]) {
                $output($type, $chunk);
            }
            if ($failure !== null) {
                throw $failure;
            }

            return new FakeProcessResult;
        });

        return $invoked;
    });

    (new RunRemoteProcess(Activity::query()->findOrFail($activity->id)))();
}

it('redacts a remote process secret that is split across output chunks', function () {
    $service = remoteProcessRedactionService($this->team, redactAll: false);
    $activity = remote_process(['docker compose up -d'], $this->server, type_uuid: $service->uuid);

    runRemoteProcessWithChunks($activity, [
        ['out', 'value is locked-ser'],
        ['err', 'error locked-se'],
        ['out', "vice-value\nnext "],
        ['err', "rvice-value\n"],
        ['out', 'line locked-service-value'],
    ]);

    $entries = collect(json_decode(Activity::query()->findOrFail($activity->id)->description, true));
    expect($entries->where('type', 'out')->pluck('output')->implode(''))->toBe('value is '.REDACTED."\nnext line ".REDACTED)
        ->and($entries->where('type', 'err')->pluck('output')->implode(''))->toBe('error '.REDACTED."\n")
        ->and($entries->pluck('output')->implode(''))->not->toContain('locked-');
});

it('redacts a private key that is split across remote process output chunks', function () {
    $activity = remote_process(['cat key'], $this->server);

    runRemoteProcessWithChunks($activity, [
        ['out', "before\n-----BEGIN OPENSSH PRIVATE KEY-----\nKEYBODYLINEONE\n"],
        ['out', "KEYBODYLINETWO\n-----END OPENSSH PRIVATE KEY-----\nafter\n"],
    ]);

    expect(RunRemoteProcess::decodeOutput(Activity::query()->findOrFail($activity->id)))
        ->toBe("before\n".REDACTED."\nafter\n");
});

it('hides an unfinished private key and keeps the last partial line of a timed out remote process', function () {
    $service = remoteProcessRedactionService($this->team, redactAll: false);
    $activity = remote_process(['cat key'], $this->server, type_uuid: $service->uuid);

    expect(fn () => runRemoteProcessWithChunks($activity, [
        ['out', "-----BEGIN PRIVATE KEY-----\nKEYBODYLINEONE\n"],
        ['err', 'tail locked-service-value'],
    ], remoteProcessTimeout()))->toThrow(RuntimeException::class);

    $output = RunRemoteProcess::decodeOutput(Activity::query()->findOrFail($activity->id));
    expect($output)->not->toContain('KEYBODYLINEONE')
        ->not->toContain('locked-service-value')
        ->toContain('tail '.REDACTED);
});
