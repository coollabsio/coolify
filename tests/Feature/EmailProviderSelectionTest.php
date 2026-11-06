<?php

use App\Livewire\Notifications\Email as NotificationEmail;
use App\Livewire\SettingsEmail;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function workingSmtpSettings(): array
{
    return [
        'smtp_enabled' => true,
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => '587',
        'smtp_encryption' => 'starttls',
        'smtp_from_address' => 'alerts@example.com',
        'smtp_from_name' => 'Coolify',
        'resend_enabled' => false,
        'resend_api_key' => null,
    ];
}

beforeEach(function () {
    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    InstanceSettings::forceCreate(['id' => 0, ...workingSmtpSettings()]);
    Once::flush();
});

test('an unsaved resend selection is not saved by a later team notification event toggle', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'admin']);
    $team->emailNotificationSettings->update([...workingSmtpSettings(), 'use_instance_email_settings' => false]);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(NotificationEmail::class)
        ->set('emailProvider', 'resend')
        ->call('selectEmailProvider')
        ->assertSet('emailProvider', 'resend')
        ->call('toggleEvent', 'deploymentSuccessEmailNotifications')
        ->call('instantSaveDeploymentCommitDetails');

    $settings = $team->emailNotificationSettings->fresh();

    expect($settings->smtp_enabled)->toBeTrue()
        ->and($settings->resend_enabled)->toBeFalse()
        ->and($settings->deployment_success_email_notifications)->toBeTrue();
});

test('an unsaved resend selection is not saved by a later instance provider toggle', function () {
    $user = User::factory()->create();
    $this->rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);

    Livewire::test(SettingsEmail::class)
        ->set('emailProvider', 'resend')
        ->call('selectEmailProvider')
        ->call('toggleResend');

    $settings = InstanceSettings::find(0);

    expect($settings->smtp_enabled)->toBeTrue()
        ->and($settings->resend_enabled)->toBeFalse();
});

test('a completed resend selection is saved on submit', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'admin']);
    $team->emailNotificationSettings->update([...workingSmtpSettings(), 'use_instance_email_settings' => false]);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(NotificationEmail::class)
        ->set('emailProvider', 'resend')
        ->call('selectEmailProvider')
        ->set('resendApiKey', 're_test_key')
        ->set('resendFromAddress', 'resend@example.com')
        ->set('resendFromName', 'Coolify')
        ->call('submit')
        ->assertHasNoErrors();

    $settings = $team->emailNotificationSettings->fresh();

    expect($settings->resend_enabled)->toBeTrue()
        ->and($settings->smtp_enabled)->toBeFalse()
        ->and($settings->resend_from_address)->toBe('resend@example.com');
});

test('turning team resend off does not require a resend sender', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'admin']);
    $team->emailNotificationSettings->update([
        'use_instance_email_settings' => false,
        'resend_enabled' => true,
        'resend_api_key' => 're_test_key',
        'smtp_from_address' => 'alerts@example.com',
    ]);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(NotificationEmail::class)
        ->set('resendEnabled', false)
        ->call('submitResend')
        ->assertHasNoErrors()
        ->assertNotDispatched('error');

    expect($team->emailNotificationSettings->fresh()->resend_enabled)->toBeFalse();
});
