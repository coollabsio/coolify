<?php

use App\Livewire\SettingsEmail;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setupInstanceAdminWithTransactionalEmail(): User
{
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    InstanceSettings::forceCreate([
        'id' => 0,
        'smtp_enabled' => true,
        'smtp_from_address' => 'hi@localhost.com',
        'smtp_from_name' => 'Coolify',
        'smtp_host' => 'coolify-mail',
        'smtp_port' => 1025,
    ]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    return $user;
}

test('send test is available in the sender section and not in the settings navbar', function () {
    $user = setupInstanceAdminWithTransactionalEmail();

    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);

    Livewire::test(SettingsEmail::class)
        ->assertOk()
        ->assertSee('Sender')
        ->assertSee('Send test')
        ->assertSeeHtml('wire:submit.prevent="sendTestEmail"');

    $view = file_get_contents(resource_path('views/livewire/settings-email.blade.php'));

    expect($view)
        ->toContain('<x-settings.layout>')
        ->toContain('settings-section title="Sender"')
        ->toContain('settings-email-send-test')
        ->not->toContain('<x-settings.navbar');
});

test('send test is not rendered when transactional email is disabled', function () {
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
        ->assertDontSee('Send test');
});
