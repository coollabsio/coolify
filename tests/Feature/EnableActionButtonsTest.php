<?php

use App\Livewire\Notifications\Discord;
use App\Livewire\Notifications\Email;
use App\Livewire\Notifications\Pushover;
use App\Livewire\Notifications\Slack;
use App\Livewire\Notifications\Telegram;
use App\Livewire\Notifications\Webhook;
use App\Livewire\SettingsEmail;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsEnableActionOwner(): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create(['email' => 'owner@example.com']);
    $user->teams()->attach($team, ['role' => 'owner']);

    session(['currentTeam' => $team]);
    test()->actingAs($user);

    return [$user, $team];
}

function actingAsEnableActionInstanceAdmin(): User
{
    $team = Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => true]);
    $user = User::factory()->create(['id' => 0, 'email' => 'root-enable-actions@example.com']);
    if (! $user->teams()->whereKey($team->id)->exists()) {
        $user->teams()->attach($team, ['role' => 'owner']);
    }

    session(['currentTeam' => $team]);
    test()->actingAs($user);

    return $user;
}

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
});

it('renders settings email enable actions instead of enabled checkboxes', function () {
    $view = file_get_contents(resource_path('views/livewire/settings-email.blade.php'));

    expect($view)->toContain('Enable SMTP Server')
        ->and($view)->toContain('Disable SMTP Server')
        ->and($view)->toContain('Enable Resend')
        ->and($view)->toContain('Disable Resend')
        ->and($view)->not->toContain('id="smtpEnabled" label="Enabled"')
        ->and($view)->not->toContain('id="resendEnabled" label="Enabled"');
});

it('keeps transactional smtp disabled when enable validation fails', function () {
    actingAsEnableActionInstanceAdmin();

    Livewire::test(SettingsEmail::class)
        ->call('toggleSmtp')
        ->assertDispatched('error')
        ->assertSet('smtpEnabled', false);

    expect(instanceSettings()->fresh()->smtp_enabled)->toBeFalse();
});

it('enables transactional smtp only after required fields validate', function () {
    actingAsEnableActionInstanceAdmin();

    Livewire::test(SettingsEmail::class)
        ->set('smtpFromAddress', 'mail@example.com')
        ->set('smtpFromName', 'Coolify')
        ->set('smtpHost', 'smtp.example.com')
        ->set('smtpPort', '587')
        ->set('smtpEncryption', 'starttls')
        ->call('toggleSmtp')
        ->assertHasNoErrors()
        ->assertSet('smtpEnabled', true)
        ->assertSet('resendEnabled', false);

    expect(instanceSettings()->fresh()->smtp_enabled)->toBeTrue()
        ->and(instanceSettings()->fresh()->resend_enabled)->toBeFalse();
});

it('renders notification provider enable actions instead of enabled checkboxes', function (string $view, string $enableLabel, string $checkboxSnippet) {
    $contents = file_get_contents(resource_path("views/livewire/notifications/{$view}.blade.php"));

    expect($contents)->toContain($enableLabel)
        ->and($contents)->not->toContain($checkboxSnippet);
})->with([
    'discord' => ['discord', 'Enable Discord', 'id="discordEnabled" label="Enabled"'],
    'slack' => ['slack', 'Enable Slack', 'id="slackEnabled" label="Enabled"'],
    'telegram' => ['telegram', 'Enable Telegram', 'id="telegramEnabled" label="Enabled"'],
    'pushover' => ['pushover', 'Enable Pushover', 'id="pushoverEnabled" label="Enabled"'],
    'webhook' => ['webhook', 'Enable Webhook', 'id="webhookEnabled" label="Enabled"'],
]);

it('shows notification provider save buttons while disabled', function (string $component) {
    actingAsEnableActionOwner();

    Livewire::test($component)
        ->assertSet(str(class_basename($component))->camel()->append('Enabled')->toString(), false)
        ->assertSee('Save');
})->with([
    'discord' => [Discord::class],
    'slack' => [Slack::class],
    'telegram' => [Telegram::class],
    'pushover' => [Pushover::class],
    'webhook' => [Webhook::class],
]);

it('hides notification provider test buttons while disabled and shows them when enabled', function (string $component, string $enabledProperty) {
    actingAsEnableActionOwner();

    Livewire::test($component)
        ->assertDontSee('Send Test Notification');

    Livewire::test($component)
        ->set($enabledProperty, true)
        ->assertSee('Send Test Notification');
})->with([
    'discord' => [Discord::class, 'discordEnabled'],
    'slack' => [Slack::class, 'slackEnabled'],
    'telegram' => [Telegram::class, 'telegramEnabled'],
    'pushover' => [Pushover::class, 'pushoverEnabled'],
    'webhook' => [Webhook::class, 'webhookEnabled'],
]);

it('hides the email test button while email notifications are disabled', function () {
    actingAsEnableActionOwner();

    Livewire::test(Email::class)
        ->assertDontSee('Send Test Email');
});

it('keeps notification providers disabled when enable validation fails', function (string $component, string $method, string $enabledProperty, string $requiredField, string $settingsRelation, string $settingsColumn) {
    [, $team] = actingAsEnableActionOwner();

    Livewire::test($component)
        ->call($method)
        ->assertDispatched('error')
        ->assertSet($enabledProperty, false);

    expect($team->{$settingsRelation}->fresh()->{$settingsColumn})->toBeFalse();
})->with([
    'discord' => [Discord::class, 'toggleDiscordEnabled', 'discordEnabled', 'discordWebhookUrl', 'discordNotificationSettings', 'discord_enabled'],
    'slack' => [Slack::class, 'toggleSlackEnabled', 'slackEnabled', 'slackWebhookUrl', 'slackNotificationSettings', 'slack_enabled'],
    'telegram' => [Telegram::class, 'toggleTelegramEnabled', 'telegramEnabled', 'telegramToken', 'telegramNotificationSettings', 'telegram_enabled'],
    'pushover' => [Pushover::class, 'togglePushoverEnabled', 'pushoverEnabled', 'pushoverUserKey', 'pushoverNotificationSettings', 'pushover_enabled'],
    'webhook' => [Webhook::class, 'toggleWebhookEnabled', 'webhookEnabled', 'webhookUrl', 'webhookNotificationSettings', 'webhook_enabled'],
]);

it('renders notification email and log drain enable actions instead of enabled checkboxes', function () {
    $notificationEmail = file_get_contents(resource_path('views/livewire/notifications/email.blade.php'));
    $logDrains = file_get_contents(resource_path('views/livewire/server/log-drains.blade.php'));

    expect($notificationEmail)->toContain('Enable SMTP Server')
        ->and($notificationEmail)->toContain('Enable Resend')
        ->and($notificationEmail)->not->toContain('id="smtpEnabled"')
        ->and($notificationEmail)->not->toContain('id="resendEnabled"')
        ->and($logDrains)->toContain('Enable New Relic')
        ->and($logDrains)->toContain('Enable Axiom')
        ->and($logDrains)->toContain('Enable Custom FluentBit')
        ->and($logDrains)->not->toContain('label="Enabled"');
});

it('keeps notification email smtp disabled when enable validation fails', function () {
    actingAsEnableActionOwner();

    Livewire::test(Email::class)
        ->call('toggleSmtp')
        ->assertDispatched('error')
        ->assertSet('smtpEnabled', false);
});
