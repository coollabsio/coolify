<?php

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Enums\ProcessStatus;
use App\Events\DatabaseStatusChanged;
use App\Events\ServiceStatusChanged;
use App\Jobs\CoolifyTask;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('constants.ssh.mux_enabled', false);
    InstanceSettings::forceCreate(['id' => 0]);

    // The user id must differ from the team id, and another team must own the user id,
    // so a team event that is sent to the user id would reach the wrong team.
    User::factory()->count(2)->create();
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    while (Team::query()->max('id') < $this->user->id) {
        Team::factory()->create();
    }
    $this->otherTeam = Team::query()->findOrFail($this->user->id);
    expect($this->user->id)->not->toBe($this->team->id);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
        'user' => 'root',
    ]);

    $this->activity = activity()
        ->causedBy($this->user)
        ->withProperties([
            'server_uuid' => $this->server->uuid,
            'command' => 'echo test',
            'type' => 'inline',
            'status' => ProcessStatus::QUEUED->value,
            'team_id' => $this->server->team_id,
        ])
        ->event('inline')
        ->log('[]');
});

it('broadcasts a team event of a finished remote process to the team of the server', function () {
    Event::fake([ServiceStatusChanged::class]);
    Process::fake(fn () => Process::result(output: 'ok'));

    (new RunRemoteProcess(Activity::query()->findOrFail($this->activity->id), call_event_on_finish: 'ServiceStatusChanged'))();

    Event::assertDispatched(ServiceStatusChanged::class, fn (ServiceStatusChanged $event) => $event->teamId === $this->team->id
        && $event->broadcastOn()[0]->name === "private-team.{$this->team->id}");
    Event::assertNotDispatched(ServiceStatusChanged::class, fn (ServiceStatusChanged $event) => $event->teamId === $this->otherTeam->id);
});

it('broadcasts a team event of a failed coolify task to the team of the server', function () {
    Event::fake([ServiceStatusChanged::class]);

    (new CoolifyTask(Activity::query()->findOrFail($this->activity->id), ignore_errors: false, call_event_on_finish: 'ServiceStatusChanged', call_event_data: null))
        ->failed(new RuntimeException('failed'));

    Event::assertDispatched(ServiceStatusChanged::class, fn (ServiceStatusChanged $event) => $event->teamId === $this->team->id);
    Event::assertNotDispatched(ServiceStatusChanged::class, fn (ServiceStatusChanged $event) => $event->teamId === $this->otherTeam->id);
});

it('still sends a user event of a finished remote process to the user who started it', function () {
    Event::fake([DatabaseStatusChanged::class]);
    Process::fake(fn () => Process::result(output: 'ok'));

    (new RunRemoteProcess(Activity::query()->findOrFail($this->activity->id), call_event_on_finish: 'DatabaseStatusChanged'))();

    Event::assertDispatched(DatabaseStatusChanged::class, fn (DatabaseStatusChanged $event) => $event->userId === $this->user->id);
});

it('keeps the explicit event data of a remote process', function () {
    Event::fake([ServiceStatusChanged::class]);
    Process::fake(fn () => Process::result(output: 'ok'));

    (new RunRemoteProcess(Activity::query()->findOrFail($this->activity->id), call_event_on_finish: 'ServiceStatusChanged', call_event_data: $this->otherTeam->id))();

    Event::assertDispatched(ServiceStatusChanged::class, fn (ServiceStatusChanged $event) => $event->teamId === $this->otherTeam->id);
});

it('does not broadcast a team event when the activity has no team', function () {
    Event::fake([ServiceStatusChanged::class]);
    Process::fake(fn () => Process::result(output: 'ok'));
    $activity = Activity::query()->findOrFail($this->activity->id);
    $activity->properties = $activity->properties->except('team_id');
    $activity->save();

    (new RunRemoteProcess($activity, call_event_on_finish: 'ServiceStatusChanged'))();

    Event::assertNotDispatched(ServiceStatusChanged::class, fn (ServiceStatusChanged $event) => $event->teamId === $this->otherTeam->id);
});
