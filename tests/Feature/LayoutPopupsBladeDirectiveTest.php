<?php

use App\Livewire\LayoutPopups;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('sponsorship reminder listener is not parsed as a blade directive', function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(LayoutPopups::class)
        ->assertSeeHtml('x-on:show-sponsorship-reminder.window="popups.sponsorship = true; reminders.sponsorship.compact = false"');
});
