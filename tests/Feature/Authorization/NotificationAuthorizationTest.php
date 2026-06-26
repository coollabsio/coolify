<?php

use App\Livewire\Notifications\Discord as DiscordNotification;
use App\Livewire\Notifications\Email as EmailNotification;
use App\Livewire\Notifications\Pushover as PushoverNotification;
use App\Livewire\Notifications\Slack as SlackNotification;
use App\Livewire\Notifications\Telegram as TelegramNotification;
use App\Livewire\Notifications\Webhook as WebhookNotification;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);
});

// --- Discord ---

test('member cannot send test notification on discord', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $settings = $this->team->discordNotificationSettings;
    $settings->update([
        'discord_enabled' => true,
        'discord_webhook_url' => 'https://discord.com/api/webhooks/test',
    ]);

    Livewire::test(DiscordNotification::class)
        ->call('sendTestNotification')
        ->assertDispatched('error');
});

test('member cannot update discord notification settings', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(DiscordNotification::class)
        ->call('syncData', true)
        ->assertForbidden();
});

test('admin can update discord notification settings', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $settings = $this->team->discordNotificationSettings;

    expect($this->admin->can('update', $settings))->toBeTrue();
});

// --- Slack ---

test('member cannot send test notification on slack', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $settings = $this->team->slackNotificationSettings;
    $settings->update([
        'slack_enabled' => true,
        'slack_webhook_url' => 'https://hooks.slack.com/services/test',
    ]);

    Livewire::test(SlackNotification::class)
        ->call('sendTestNotification')
        ->assertDispatched('error');
});

test('member cannot update slack notification settings', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(SlackNotification::class)
        ->call('syncData', true)
        ->assertForbidden();
});

test('admin can update slack notification settings', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $settings = $this->team->slackNotificationSettings;

    expect($this->admin->can('update', $settings))->toBeTrue();
});

// --- Telegram ---

test('member cannot send test notification on telegram', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $settings = $this->team->telegramNotificationSettings;
    $settings->update([
        'telegram_enabled' => true,
        'telegram_token' => 'test-token',
        'telegram_chat_id' => '123456',
    ]);

    Livewire::test(TelegramNotification::class)
        ->call('sendTestNotification')
        ->assertDispatched('error');
});

test('member cannot update telegram notification settings', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(TelegramNotification::class)
        ->call('syncData', true)
        ->assertForbidden();
});

test('admin can update telegram notification settings', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $settings = $this->team->telegramNotificationSettings;

    expect($this->admin->can('update', $settings))->toBeTrue();
});

// --- Email ---

test('member cannot send test email notification', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $settings = $this->team->emailNotificationSettings;
    $settings->update([
        'smtp_enabled' => true,
        'smtp_host' => 'localhost',
        'smtp_port' => 587,
        'smtp_from_address' => 'test@test.com',
        'smtp_from_name' => 'Test',
    ]);

    Livewire::test(EmailNotification::class)
        ->call('sendTestEmail')
        ->assertDispatched('error');
});

test('member cannot update email notification settings', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(EmailNotification::class)
        ->call('syncData', true)
        ->assertForbidden();
});

test('member cannot copy instance email settings', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(EmailNotification::class)
        ->call('copyFromInstanceSettings')
        ->assertForbidden();
});

test('admin can update email notification settings', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $settings = $this->team->emailNotificationSettings;

    expect($this->admin->can('update', $settings))->toBeTrue();
});

// --- Pushover ---

test('member cannot send test notification on pushover', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $settings = $this->team->pushoverNotificationSettings;
    $settings->update([
        'pushover_enabled' => true,
        'pushover_app_token' => 'test-token',
        'pushover_user_key' => 'test-user-key',
    ]);

    Livewire::test(PushoverNotification::class)
        ->call('sendTestNotification')
        ->assertDispatched('error');
});

test('member cannot update pushover notification settings', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(PushoverNotification::class)
        ->call('syncData', true)
        ->assertForbidden();
});

test('admin can update pushover notification settings', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $settings = $this->team->pushoverNotificationSettings;

    expect($this->admin->can('update', $settings))->toBeTrue();
});

// --- Webhook ---

test('member cannot send test notification on webhook', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $settings = $this->team->webhookNotificationSettings;
    $settings->update([
        'webhook_enabled' => true,
        'webhook_url' => 'https://example.com/webhook',
    ]);

    Livewire::test(WebhookNotification::class)
        ->call('sendTestNotification')
        ->assertDispatched('error');
});

test('member cannot update webhook notification settings', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(WebhookNotification::class)
        ->call('syncData', true)
        ->assertForbidden();
});

test('admin can update webhook notification settings', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $settings = $this->team->webhookNotificationSettings;

    expect($this->admin->can('update', $settings))->toBeTrue();
});

// --- Send test policy checks ---

test('admin can send test on all notification channels', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect($this->admin->can('sendTest', $this->team->discordNotificationSettings))->toBeTrue();
    expect($this->admin->can('sendTest', $this->team->slackNotificationSettings))->toBeTrue();
    expect($this->admin->can('sendTest', $this->team->telegramNotificationSettings))->toBeTrue();
    expect($this->admin->can('sendTest', $this->team->emailNotificationSettings))->toBeTrue();
    expect($this->admin->can('sendTest', $this->team->pushoverNotificationSettings))->toBeTrue();
    expect($this->admin->can('sendTest', $this->team->webhookNotificationSettings))->toBeTrue();
});

test('member cannot send test on any notification channel', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect($this->member->can('sendTest', $this->team->discordNotificationSettings))->toBeFalse();
    expect($this->member->can('sendTest', $this->team->slackNotificationSettings))->toBeFalse();
    expect($this->member->can('sendTest', $this->team->telegramNotificationSettings))->toBeFalse();
    expect($this->member->can('sendTest', $this->team->emailNotificationSettings))->toBeFalse();
    expect($this->member->can('sendTest', $this->team->pushoverNotificationSettings))->toBeFalse();
    expect($this->member->can('sendTest', $this->team->webhookNotificationSettings))->toBeFalse();
});

test('member cannot view notification secrets', function (string $component, string $settingsRelation, array $secrets) {
    $settings = $this->team->{$settingsRelation};
    $settings->update($secrets);

    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $componentTest = Livewire::test($component);

    foreach ($secrets as $column => $value) {
        $property = str($column)->camel()->toString();

        $componentTest
            ->assertSet($property, null)
            ->assertDontSee($value);
    }

    $componentTest->assertSee('Hidden (only admins can view)');
})->with([
    'discord webhook' => [DiscordNotification::class, 'discordNotificationSettings', [
        'discord_webhook_url' => 'https://discord.com/api/webhooks/secret-member',
    ]],
    'slack webhook' => [SlackNotification::class, 'slackNotificationSettings', [
        'slack_webhook_url' => 'https://hooks.slack.com/services/secret-member',
    ]],
    'telegram token and chat id' => [TelegramNotification::class, 'telegramNotificationSettings', [
        'telegram_token' => 'telegram-secret-token',
        'telegram_chat_id' => 'telegram-secret-chat',
    ]],
    'pushover credentials' => [PushoverNotification::class, 'pushoverNotificationSettings', [
        'pushover_user_key' => 'pushover-secret-user',
        'pushover_api_token' => 'pushover-secret-token',
    ]],
    'generic webhook' => [WebhookNotification::class, 'webhookNotificationSettings', [
        'webhook_url' => 'https://example.com/secret-webhook',
    ]],
]);

test('admin can view notification secrets', function (string $component, string $settingsRelation, array $secrets) {
    $settings = $this->team->{$settingsRelation};
    $settings->update($secrets);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $componentTest = Livewire::test($component);

    foreach ($secrets as $column => $value) {
        $property = str($column)->camel()->toString();

        $componentTest->assertSet($property, $value);
    }
})->with([
    'discord webhook' => [DiscordNotification::class, 'discordNotificationSettings', [
        'discord_webhook_url' => 'https://discord.com/api/webhooks/secret-admin',
    ]],
    'slack webhook' => [SlackNotification::class, 'slackNotificationSettings', [
        'slack_webhook_url' => 'https://hooks.slack.com/services/secret-admin',
    ]],
    'telegram token and chat id' => [TelegramNotification::class, 'telegramNotificationSettings', [
        'telegram_token' => 'telegram-admin-token',
        'telegram_chat_id' => 'telegram-admin-chat',
    ]],
    'pushover credentials' => [PushoverNotification::class, 'pushoverNotificationSettings', [
        'pushover_user_key' => 'pushover-admin-user',
        'pushover_api_token' => 'pushover-admin-token',
    ]],
    'generic webhook' => [WebhookNotification::class, 'webhookNotificationSettings', [
        'webhook_url' => 'https://example.com/admin-webhook',
    ]],
]);
