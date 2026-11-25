<?php

use App\Livewire\SettingsEmail;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('send test is disabled when transactional email is disabled', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    InstanceSettings::forceCreate([
        'id' => 0,
        'smtp_enabled' => false,
        'resend_enabled' => false,
    ]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);

    Livewire::test(SettingsEmail::class)
        ->assertOk()
        ->assertSee('Save an email provider first.')
        ->assertDontSee('Send Test Email');
});
