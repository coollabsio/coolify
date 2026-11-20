<?php

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Enums\ProcessStatus;
use App\Jobs\CoolifyTask;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Support\RemoteProcessCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('constants.ssh.mux_enabled', false);
    InstanceSettings::forceCreate(['id' => 0]);

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'user' => 'root',
    ]);
    $this->secretCommand = "echo 'POSTGRES_PASSWORD=super-secret-value' > /tmp/compose.env";
});

it('stores the command of a remote process encrypted', function () {
    Queue::fake();

    $activity = remote_process([$this->secretCommand], $this->server);

    $stored = Activity::query()->findOrFail($activity->id);
    expect(json_encode($stored->properties))->not->toContain('super-secret-value')
        ->and(RemoteProcessCommand::read($stored))->toBe($this->secretCommand);
    Queue::assertPushed(CoolifyTask::class);
});

it('still reads a plain command that was queued before the upgrade', function () {
    $activity = activity()
        ->withProperties(['server_uuid' => $this->server->uuid, 'command' => 'echo legacy', 'type' => 'inline', 'status' => ProcessStatus::QUEUED->value])
        ->event('inline')
        ->log('[]');

    expect(RemoteProcessCommand::read($activity))->toBe('echo legacy');
});

it('removes the command after a successful final run', function () {
    Queue::fake();
    Process::fake(fn () => Process::result(output: 'ok'));
    $activity = remote_process([$this->secretCommand], $this->server);

    (new CoolifyTask(Activity::query()->findOrFail($activity->id), ignore_errors: false, call_event_on_finish: null, call_event_data: null))->handle();

    $stored = Activity::query()->findOrFail($activity->id);
    expect($stored->getExtraProperty('command'))->toBeNull()
        ->and($stored->getExtraProperty('command_encrypted'))->toBeNull()
        ->and($stored->getExtraProperty('status'))->toBe(ProcessStatus::FINISHED->value);
});

it('keeps the command after one attempt so the queue can retry the task', function () {
    Queue::fake();
    Process::fake(fn () => Process::result(errorOutput: 'Connection reset', exitCode: 255));
    $activity = remote_process([$this->secretCommand], $this->server);

    $run = new RunRemoteProcess(activity: Activity::query()->findOrFail($activity->id));
    expect(fn () => $run())->toThrow(RuntimeException::class);

    expect(RemoteProcessCommand::read(Activity::query()->findOrFail($activity->id)))->toBe($this->secretCommand);
});

it('removes the command when the task fails permanently', function () {
    Queue::fake();
    $activity = remote_process([$this->secretCommand], $this->server);

    (new CoolifyTask(Activity::query()->findOrFail($activity->id), ignore_errors: false, call_event_on_finish: null, call_event_data: null))
        ->failed(new RuntimeException('SSH connection failed'));

    $stored = Activity::query()->findOrFail($activity->id);
    expect($stored->getExtraProperty('command'))->toBeNull()
        ->and($stored->getExtraProperty('status'))->toBe(ProcessStatus::ERROR->value);
});

it('queues a remote process on the high queue by default', function () {
    Queue::fake();

    remote_process(['echo ok'], $this->server);

    Queue::assertPushedOn('high', CoolifyTask::class);
});

it('queues a remote process on the requested queue', function () {
    Queue::fake();

    remote_process(['echo ok'], $this->server, queue: 'deployments');

    Queue::assertPushedOn('deployments', CoolifyTask::class);
});
