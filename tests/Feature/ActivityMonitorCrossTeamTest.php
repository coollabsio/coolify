<?php

use App\Livewire\ActivityMonitor;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\CannotUpdateLockedPropertyException;
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

test('hydrateActivity allows access to own teams activity via team_id', function () {
    $ownActivity = Activity::create([
        'log_name' => 'default',
        'description' => 'test activity',
        'properties' => ['team_id' => $this->team->id],
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $component = Livewire::test(ActivityMonitor::class)
        ->call('newMonitorActivity', $ownActivity->id);

    expect($component->get('activity'))->not->toBeNull();
    expect($component->get('activity')->id)->toBe($ownActivity->id);
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
