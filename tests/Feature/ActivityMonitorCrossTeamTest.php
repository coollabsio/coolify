<?php

use App\Livewire\ActivityMonitor;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->otherTeam = Team::factory()->create();
});

test('hydrateActivity blocks access to another teams activity via team_id', function () {
    $otherActivity = Activity::create([
        'log_name' => 'default',
        'description' => 'test activity',
        'properties' => ['team_id' => $this->otherTeam->id],
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);

    Livewire::test(ActivityMonitor::class)
        ->call('newMonitorActivity', $otherActivity->id)
        ->assertSet('activity', null);
});

test('hydrateActivity blocks an activity of the own team that has no resource or server', function () {
    $ownActivity = Activity::create([
        'log_name' => 'default',
        'description' => 'test activity',
        'properties' => ['team_id' => $this->team->id],
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);

    Livewire::test(ActivityMonitor::class)
        ->call('newMonitorActivity', $ownActivity->id)
        ->assertSet('activity', null);
});

test('hydrateActivity blocks access to activity without team_id or server_uuid', function () {
    $legacyActivity = Activity::create([
        'log_name' => 'default',
        'description' => 'legacy activity',
        'properties' => [],
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);

    Livewire::test(ActivityMonitor::class)
        ->call('newMonitorActivity', $legacyActivity->id)
        ->assertSet('activity', null);
});

test('hydrateActivity blocks access to activity from another teams server via server_uuid', function () {
    $otherServer = Server::factory()->create([
        'team_id' => $this->otherTeam->id,
    ]);

    $otherActivity = Activity::create([
        'log_name' => 'default',
        'description' => 'test activity',
        'properties' => ['server_uuid' => $otherServer->uuid],
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);

    Livewire::test(ActivityMonitor::class)
        ->call('newMonitorActivity', $otherActivity->id)
        ->assertSet('activity', null);
});

test('hydrateActivity allows access to activity from own teams server via server_uuid', function () {
    $ownServer = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $ownActivity = Activity::create([
        'log_name' => 'default',
        'description' => 'test activity',
        'properties' => ['server_uuid' => $ownServer->uuid],
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $component = Livewire::test(ActivityMonitor::class)
        ->call('newMonitorActivity', $ownActivity->id);

    expect($component->get('activity'))->not->toBeNull();
    expect($component->get('activity')->id)->toBe($ownActivity->id);
});

test('hydrateActivity returns null for non-existent activity id', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);

    Livewire::test(ActivityMonitor::class)
        ->call('newMonitorActivity', 99999)
        ->assertSet('activity', null);
});

test('activityId property is locked and cannot be set from client', function () {
    $otherActivity = Activity::create([
        'log_name' => 'default',
        'description' => 'test activity',
        'properties' => ['team_id' => $this->otherTeam->id],
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);

    // Attempting to set a #[Locked] property from the client should throw
    Livewire::test(ActivityMonitor::class)
        ->set('activityId', $otherActivity->id)
        ->assertStatus(500);
})->throws(CannotUpdateLockedPropertyException::class);

test('closing a process dialog clears the previous activity logs', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $activity = Activity::create([
        'log_name' => 'default',
        'description' => 'proxy restart activity',
        'properties' => ['team_id' => $this->team->id, 'server_uuid' => $server->uuid],
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);

    Livewire::test(ActivityMonitor::class)
        ->call('newMonitorActivity', $activity->id)
        ->assertSet('activityId', $activity->id)
        ->assertSet('isPollingActive', true)
        ->dispatch('processDialogClosed')
        ->assertSet('activityId', null)
        ->assertSet('activity', null)
        ->assertSet('isPollingActive', false);
});

describe('activity output of a resource', function () {
    beforeEach(function () {
        $this->server = Server::factory()->create(['team_id' => $this->team->id]);
        $destination = $this->server->standaloneDockers()->firstOrFail();
        $project = Project::factory()->create(['team_id' => $this->team->id]);
        $this->service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'environment_id' => $project->environments()->firstOrFail()->id,
        ]);
        $this->serviceActivity = Activity::create([
            'log_name' => 'default',
            'description' => 'service start',
            'properties' => [
                'team_id' => $this->team->id,
                'server_uuid' => $this->server->uuid,
                'type_uuid' => $this->service->uuid,
            ],
        ]);
        $this->serverActivity = Activity::create([
            'log_name' => 'default',
            'description' => 'proxy start',
            'properties' => ['team_id' => $this->team->id, 'server_uuid' => $this->server->uuid],
        ]);
    });

    test('a team member cannot read it', function (string $activity) {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        $this->actingAs($member);
        session(['currentTeam' => ['id' => $this->team->id]]);

        Livewire::test(ActivityMonitor::class)
            ->call('newMonitorActivity', $this->{$activity}->id)
            ->assertSet('activity', null);
    })->with(['serviceActivity', 'serverActivity']);

    test('a team admin can read it', function (string $activity) {
        $admin = User::factory()->create();
        $this->team->members()->attach($admin->id, ['role' => 'admin']);
        $this->actingAs($admin);
        session(['currentTeam' => ['id' => $this->team->id]]);

        $component = Livewire::test(ActivityMonitor::class)
            ->call('newMonitorActivity', $this->{$activity}->id);

        expect($component->get('activity')?->id)->toBe($this->{$activity}->id);
    })->with(['serviceActivity', 'serverActivity']);

    test('a member who owns another team cannot read it after switching to that team', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        $this->otherTeam->members()->attach($member->id, ['role' => 'owner']);
        $this->actingAs($member);
        session(['currentTeam' => ['id' => $this->otherTeam->id]]);

        Livewire::test(ActivityMonitor::class)
            ->call('newMonitorActivity', $this->serviceActivity->id)
            ->assertSet('activity', null);
    });

    test('an admin of the resource team can read it while the session is on another team', function () {
        $admin = User::factory()->create();
        $this->team->members()->attach($admin->id, ['role' => 'admin']);
        $this->otherTeam->members()->attach($admin->id, ['role' => 'owner']);
        $this->actingAs($admin);
        session(['currentTeam' => ['id' => $this->otherTeam->id]]);

        $component = Livewire::test(ActivityMonitor::class)
            ->call('newMonitorActivity', $this->serviceActivity->id);

        expect($component->get('activity')?->id)->toBe($this->serviceActivity->id);
    });
});

test('the process status shows running until the activity ends, then its result', function (?int $exitCode, string $status) {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $activity = Activity::create([
        'log_name' => 'default',
        'description' => 'proxy restart activity',
        'properties' => ['team_id' => $this->team->id, 'server_uuid' => $server->uuid],
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $component = Livewire::test(ActivityMonitor::class)
        ->call('newMonitorActivity', $activity->id)
        ->assertSeeHtml('data-activity-status="running"');

    $activity->update(['properties' => $activity->properties->put('exitCode', $exitCode)]);

    $component->call('polling')
        ->assertSet('isPollingActive', $exitCode === null)
        ->assertSeeHtml("data-activity-status=\"{$status}\"");
})->with([
    'still running' => [null, 'running'],
    'succeeded' => [0, 'finished'],
    'failed' => [1, 'failed'],
]);
