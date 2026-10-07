<?php

use App\Livewire\Team\AuditLog;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();
    $this->secondTeam = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'admin']);
    $this->secondTeam->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('the audit log is forbidden when the session has no team', function () {
    session()->forget('currentTeam');

    Livewire::test(AuditLog::class)->assertForbidden();
});

test('an open audit log is forbidden after the session team is removed from the user', function () {
    $component = Livewire::test(AuditLog::class)->assertOk();

    $this->team->members()->detach($this->user->id);
    auth()->setUser($this->user->fresh());

    $component->set('search', 'deployment')->assertForbidden();
});

test('team admins can still open the audit log', function () {
    Livewire::test(AuditLog::class)->assertOk();
});

test('team members cannot open the audit log', function () {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    auth()->setUser($this->user->fresh());

    Livewire::test(AuditLog::class)->assertForbidden();
});
