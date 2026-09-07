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
use Livewire\Exceptions\MethodNotFoundException;
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

test('the private discord syncData helper is not remotely callable', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(DiscordNotification::class);

    expect(fn () => $component->call('syncData', true))
        ->toThrow(MethodNotFoundException::class);
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

test('the private slack syncData helper is not remotely callable', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(SlackNotification::class);

    expect(fn () => $component->call('syncData', true))
        ->toThrow(MethodNotFoundException::class);
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

test('the private telegram syncData helper is not remotely callable', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(TelegramNotification::class);

    expect(fn () => $component->call('syncData', true))
        ->toThrow(MethodNotFoundException::class);
});

test('admin can update telegram notification settings', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $settings = $this->team->telegramNotificationSettings;

    expect($this->admin->can('update', $settings))->toBeTrue();
});

test('telegram restart limit thread id accepts 255 characters', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(TelegramNotification::class)
        ->set('telegramNotificationsRestartLimitReachedThreadId', str_repeat('a', 255))
        ->call('submit')
        ->assertHasNoErrors(['telegramNotificationsRestartLimitReachedThreadId']);

    expect($this->team->telegramNotificationSettings->fresh()->telegram_notifications_restart_limit_reached_thread_id)
        ->toBe(str_repeat('a', 255));
});

test('telegram restart limit thread id rejects 256 characters', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(TelegramNotification::class)
        ->set('telegramNotificationsRestartLimitReachedThreadId', str_repeat('a', 256))
        ->call('submit')
        ->assertDispatched('error');

    expect($this->team->telegramNotificationSettings->fresh()->telegram_notifications_restart_limit_reached_thread_id)
        ->not->toBe(str_repeat('a', 256));
});

test('member cannot view telegram thread ids', function () {
    $threadIds = [
        'telegram_notifications_deployment_success_thread_id' => 'deployment-success-thread',
        'telegram_notifications_deployment_failure_thread_id' => 'deployment-failure-thread',
        'telegram_notifications_status_change_thread_id' => 'status-change-thread',
        'telegram_notifications_restart_limit_reached_thread_id' => 'restart-limit-thread',
        'telegram_notifications_backup_success_thread_id' => 'backup-success-thread',
        'telegram_notifications_backup_failure_thread_id' => 'backup-failure-thread',
        'telegram_notifications_scheduled_task_success_thread_id' => 'scheduled-task-success-thread',
        'telegram_notifications_scheduled_task_failure_thread_id' => 'scheduled-task-failure-thread',
        'telegram_notifications_docker_cleanup_success_thread_id' => 'docker-cleanup-success-thread',
        'telegram_notifications_docker_cleanup_failure_thread_id' => 'docker-cleanup-failure-thread',
        'telegram_notifications_server_disk_usage_thread_id' => 'server-disk-usage-thread',
        'telegram_notifications_server_reachable_thread_id' => 'server-reachable-thread',
        'telegram_notifications_server_unreachable_thread_id' => 'server-unreachable-thread',
        'telegram_notifications_server_patch_thread_id' => 'server-patch-thread',
        'telegram_notifications_traefik_outdated_thread_id' => 'traefik-outdated-thread',
    ];

    $this->team->telegramNotificationSettings->update($threadIds);

    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(TelegramNotification::class);

    foreach ($threadIds as $column => $threadId) {
        $component
            ->assertSet(str($column)->camel()->toString(), null)
            ->assertDontSee($threadId);
    }
});

test('admin can view telegram thread ids', function () {
    $threadIds = [
        'telegram_notifications_deployment_success_thread_id' => 'deployment-success-thread',
        'telegram_notifications_restart_limit_reached_thread_id' => 'restart-limit-thread',
    ];

    $this->team->telegramNotificationSettings->update($threadIds);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(TelegramNotification::class);

    foreach ($threadIds as $column => $threadId) {
        $component->assertSet(str($column)->camel()->toString(), $threadId);
    }
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

test('the private email syncData helper is not remotely callable', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(EmailNotification::class);

    expect(fn () => $component->call('syncData', true))
        ->toThrow(MethodNotFoundException::class);
});

test('member cannot update smtp email transport directly', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(EmailNotification::class)
        ->set('smtpFromAddress', 'member@example.com')
        ->set('smtpFromName', 'Member')
        ->set('smtpHost', 'smtp.example.com')
        ->set('smtpPort', '587')
        ->set('smtpEncryption', 'starttls')
        ->set('smtpPassword', 'member-smtp-password')
        ->call('submitSmtp')
        ->assertForbidden();
});

test('member cannot update resend email transport directly', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(EmailNotification::class)
        ->set('smtpFromAddress', 'member@example.com')
        ->set('smtpFromName', 'Member')
        ->set('resendApiKey', 'member-resend-api-key')
        ->call('submitResend')
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

test('admin can save team smtp settings without a resend api key when resend is disabled', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(EmailNotification::class)
        ->set('smtpFromAddress', 'alerts@example.com')
        ->set('smtpFromName', 'Coolify')
        ->set('smtpHost', 'smtp.example.com')
        ->set('smtpPort', '587')
        ->set('smtpEncryption', 'starttls')
        ->set('resendEnabled', false)
        ->set('resendApiKey', null)
        ->call('submitResend')
        ->assertHasNoErrors()
        ->assertNotDispatched('error');
});

test('admin cannot enable team resend without an api key', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(EmailNotification::class)
        ->set('smtpFromAddress', 'alerts@example.com')
        ->set('smtpFromName', 'Coolify')
        ->set('resendEnabled', true)
        ->set('resendApiKey', null)
        ->call('submitResend')
        ->assertDispatched('error');

    expect($this->team->emailNotificationSettings()->first()->resend_enabled)->toBeFalse();
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

test('the private pushover syncData helper is not remotely callable', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(PushoverNotification::class);

    expect(fn () => $component->call('syncData', true))
        ->toThrow(MethodNotFoundException::class);
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

test('the private webhook syncData helper is not remotely callable', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(WebhookNotification::class);

    expect(fn () => $component->call('syncData', true))
        ->toThrow(MethodNotFoundException::class);
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
    'email credentials' => [EmailNotification::class, 'emailNotificationSettings', [
        'smtp_password' => 'smtp-secret-password',
        'resend_api_key' => 'resend-secret-api-key',
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
    'email credentials' => [EmailNotification::class, 'emailNotificationSettings', [
        'smtp_password' => 'smtp-admin-password',
        'resend_api_key' => 'resend-admin-api-key',
    ]],
]);
