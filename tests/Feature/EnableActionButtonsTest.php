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

    // The channel actions always render "Send test"; it stays disabled until the channel is enabled.
    $sendTestButton = '/<button\s+(disabled\s+)?class="button"[^>]*\$wire\.\$call\(testMethod\)[^>]*>/';

    preg_match($sendTestButton, Livewire::test($component)->html(), $disabledMatch);
    expect($disabledMatch[1] ?? null)->not->toBeEmpty();

    preg_match($sendTestButton, Livewire::test($component)->set($enabledProperty, true)->html(), $enabledMatch);
    expect($enabledMatch)->not->toBeEmpty()
        ->and($enabledMatch[1] ?? '')->toBe('');
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

it('keeps notification email smtp disabled when enable validation fails', function () {
    actingAsEnableActionOwner();

    Livewire::test(Email::class)
        ->call('toggleSmtp')
        ->assertDispatched('error')
        ->assertSet('smtpEnabled', false);
});

it('reverts a notification provider toggle to the saved value when enabling fails', function (string $component, string $method, string $enabledProperty, string $settingsRelation, string $settingsColumn) {
    [, $team] = actingAsEnableActionOwner();

    Livewire::test($component)
        ->set($enabledProperty, true)
        ->call($method)
        ->assertDispatched('error')
        ->assertSet($enabledProperty, false);

    expect($team->{$settingsRelation}->fresh()->{$settingsColumn})->toBeFalse();
})->with([
    'discord' => [Discord::class, 'instantSaveDiscordEnabled', 'discordEnabled', 'discordNotificationSettings', 'discord_enabled'],
    'slack' => [Slack::class, 'instantSaveSlackEnabled', 'slackEnabled', 'slackNotificationSettings', 'slack_enabled'],
    'telegram' => [Telegram::class, 'instantSaveTelegramEnabled', 'telegramEnabled', 'telegramNotificationSettings', 'telegram_enabled'],
    'pushover' => [Pushover::class, 'instantSavePushoverEnabled', 'pushoverEnabled', 'pushoverNotificationSettings', 'pushover_enabled'],
    'webhook' => [Webhook::class, 'instantSaveWebhookEnabled', 'webhookEnabled', 'webhookNotificationSettings', 'webhook_enabled'],
]);

it('reverts a notification provider toggle to the saved value when disabling fails', function (string $component, string $method, string $enabledProperty, string $requiredProperty, string $settingsRelation, array $savedSettings) {
    [, $team] = actingAsEnableActionOwner();
    $team->{$settingsRelation}->update($savedSettings);

    Livewire::test($component)
        ->assertSet($enabledProperty, true)
        ->set($requiredProperty, '')
        ->set($enabledProperty, false)
        ->call($method)
        ->assertDispatched('error')
        ->assertSet($enabledProperty, true);

    expect($team->{$settingsRelation}->fresh()->{array_key_first($savedSettings)})->toBeTrue();
})->with([
    'discord' => [Discord::class, 'instantSaveDiscordEnabled', 'discordEnabled', 'discordWebhookUrl', 'discordNotificationSettings', ['discord_enabled' => true, 'discord_webhook_url' => 'https://discord.com/api/webhooks/1/abc']],
    'slack' => [Slack::class, 'instantSaveSlackEnabled', 'slackEnabled', 'slackWebhookUrl', 'slackNotificationSettings', ['slack_enabled' => true, 'slack_webhook_url' => 'https://hooks.slack.com/services/T/B/C']],
    'telegram' => [Telegram::class, 'instantSaveTelegramEnabled', 'telegramEnabled', 'telegramToken', 'telegramNotificationSettings', ['telegram_enabled' => true, 'telegram_token' => '123:abc', 'telegram_chat_id' => '42']],
    'pushover' => [Pushover::class, 'instantSavePushoverEnabled', 'pushoverEnabled', 'pushoverUserKey', 'pushoverNotificationSettings', ['pushover_enabled' => true, 'pushover_user_key' => 'user', 'pushover_api_token' => 'token']],
    'webhook' => [Webhook::class, 'instantSaveWebhookEnabled', 'webhookEnabled', 'webhookUrl', 'webhookNotificationSettings', ['webhook_enabled' => true, 'webhook_url' => 'https://hooks.example.com/coolify']],
]);

it('reverts a notification event toggle to the saved value when saving fails', function (string $component, string $urlProperty, string $eventProperty, string $settingsRelation, string $eventColumn) {
    [, $team] = actingAsEnableActionOwner();
    $saved = (bool) $team->{$settingsRelation}->{$eventColumn};

    Livewire::test($component)
        ->set($urlProperty, 'not-a-url')
        ->call('toggleEvent', $eventProperty)
        ->assertSet($eventProperty, $saved);

    expect((bool) $team->{$settingsRelation}->fresh()->{$eventColumn})->toBe($saved);
})->with([
    'discord' => [Discord::class, 'discordWebhookUrl', 'deploymentSuccessDiscordNotifications', 'discordNotificationSettings', 'deployment_success_discord_notifications'],
    'slack' => [Slack::class, 'slackWebhookUrl', 'deploymentSuccessSlackNotifications', 'slackNotificationSettings', 'deployment_success_slack_notifications'],
]);

it('reverts the discord mention setting to the saved value when saving fails', function () {
    [, $team] = actingAsEnableActionOwner();
    $team->discordNotificationSettings->update(['discord_ping_enabled' => true]);

    Livewire::test(Discord::class)
        ->set('discordWebhookUrl', 'not-a-url')
        ->set('discordPingEnabled', false)
        ->call('instantSaveDiscordPingEnabled')
        ->assertSet('discordPingEnabled', true);

    expect($team->discordNotificationSettings->fresh()->discord_ping_enabled)->toBeTrue();
});
