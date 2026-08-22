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

function setupInstanceAdminForEmailSettings(): User
{
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    InstanceSettings::forceCreate([
        'id' => 0,
        'smtp_enabled' => false,
        'resend_enabled' => false,
    ]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    return $user;
}

function smtpSetupPayload(): array
{
    return [
        'smtpFromAddress' => 'alerts@example.com',
        'smtpFromName' => 'Coolify',
        'smtpHost' => 'smtp.example.com',
        'smtpPort' => '587',
        'smtpEncryption' => 'starttls',
        'smtpEhloDomain' => 'coolify.example.com',
        'resendEnabled' => false,
        'resendApiKey' => null,
    ];
}

test('saving smtp settings does not require a resend api key when resend is disabled', function () {
    $user = setupInstanceAdminForEmailSettings();

    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);

    Livewire::test(SettingsEmail::class)
        ->fill(smtpSetupPayload())
        ->set('smtpEnabled', true)
        ->call('submitSmtp')
        ->assertHasNoErrors()
        ->assertNotDispatched('error');

    $settings = InstanceSettings::find(0);

    expect($settings->smtp_enabled)->toBeTrue()
        ->and($settings->smtp_host)->toBe('smtp.example.com')
        ->and($settings->smtp_ehlo_domain)->toBe('coolify.example.com')
        ->and($settings->resend_enabled)->toBeFalse();
});

test('team smtp settings save their own ehlo domain', function () {
    setupInstanceAdminForEmailSettings();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(NotificationEmail::class)
        ->fill(smtpSetupPayload())
        ->set('smtpEnabled', true)
        ->call('submitSmtp')
        ->assertHasNoErrors()
        ->assertNotDispatched('error');

    expect($team->emailNotificationSettings->fresh()->smtp_ehlo_domain)
        ->toBe('coolify.example.com');
});

test('saving transactional email settings does not require a resend api key when resend is disabled', function () {
    $user = setupInstanceAdminForEmailSettings();

    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);

    Livewire::test(SettingsEmail::class)
        ->fill(smtpSetupPayload())
        ->call('submit')
        ->assertHasNoErrors()
        ->assertNotDispatched('error');

    $settings = InstanceSettings::find(0);

    expect($settings->smtp_host)->toBe('smtp.example.com')
        ->and($settings->resend_enabled)->toBeFalse()
        ->and($settings->resend_api_key)->toBeNull();
});

test('enabling smtp delivery does not require a resend api key when resend is disabled', function () {
    $user = setupInstanceAdminForEmailSettings();

    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);

    Livewire::test(SettingsEmail::class)
        ->fill(smtpSetupPayload())
        ->set('smtpEnabled', true)
        ->call('instantSaveSmtp')
        ->assertHasNoErrors()
        ->assertNotDispatched('error');

    $settings = InstanceSettings::find(0);

    expect($settings->smtp_enabled)->toBeTrue()
        ->and($settings->resend_enabled)->toBeFalse();
});

test('disabling resend does not require a resend api key', function () {
    $user = setupInstanceAdminForEmailSettings();

    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);

    Livewire::test(SettingsEmail::class)
        ->fill(smtpSetupPayload())
        ->set('resendEnabled', false)
        ->set('resendApiKey', null)
        ->call('submitResend')
        ->assertHasNoErrors()
        ->assertNotDispatched('error');
});

test('enabling resend still requires an api key', function () {
    $user = setupInstanceAdminForEmailSettings();

    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);

    Livewire::test(SettingsEmail::class)
        ->fill(smtpSetupPayload())
        ->set('resendEnabled', true)
        ->set('resendApiKey', null)
        ->call('submitResend')
        ->assertDispatched('error');

    expect(InstanceSettings::find(0)->resend_enabled)->toBeFalse();
});

test('email settings page has a single unsaved bar so smtp save cannot hit resend validation', function () {
    $view = file_get_contents(resource_path('views/livewire/settings-email.blade.php'));

    expect(substr_count($view, '<x-unsaved-bar'))->toBe(1)
        ->and($view)->toContain('action="submit"')
        ->and($view)->not->toContain('action="submitResend"');
});
