<?php

use App\Models\DiscordNotificationSettings;
use App\Models\EmailNotificationSettings;
use App\Models\InstanceSettings;
use App\Models\NtfyNotificationSettings;
use App\Models\PushoverNotificationSettings;
use App\Models\SlackNotificationSettings;
use App\Models\Team;
use App\Models\TelegramNotificationSettings;
use App\Models\User;
use App\Models\WebhookNotificationSettings;
use App\Rules\SafeWebhookUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.driver' => 'file',
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::query()->whereKey(0)->delete();
    $settings = new InstanceSettings(['is_api_enabled' => true]);
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    // Webhook URLs and the SMTP host are resolved during validation: answer without DNS.
    SafeWebhookUrl::resolveHostsUsing(fn (): array => ['93.184.216.34']);
});

afterEach(function () {
    SafeWebhookUrl::resolveHostsUsing(null);
});

function authHeaders(string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];
}

describe('GET /api/v1/notifications/*', function () {
    test('returns email notification settings for the current team', function () {
        $response = $this->withHeaders(authHeaders($this->bearerToken))
            ->getJson('/api/v1/notifications/email');

        $response->assertSuccessful();
        $response->assertJsonFragment([
            'team_id' => $this->team->id,
            'smtp_enabled' => false,
        ]);
        $response->assertJsonStructure([
            'team_id',
            'smtp_enabled',
            'smtp_ehlo_domain',
            'deployment_failure_email_notifications',
            'use_instance_email_settings',
        ]);
    });

    test('returns settings for every notification channel', function (string $channel) {
        $response = $this->withHeaders(authHeaders($this->bearerToken))
            ->getJson("/api/v1/notifications/{$channel}");

        $response->assertSuccessful();
        $response->assertJsonPath('team_id', $this->team->id);
    })->with([
        'email',
        'discord',
        'slack',
        'telegram',
        'pushover',
        'ntfy',
        'webhook',
    ]);

    test('hides encrypted secrets without read:sensitive ability', function () {
        $this->team->discordNotificationSettings->update([
            'discord_webhook_url' => 'https://discord.com/api/webhooks/hidden/secret',
            'discord_enabled' => true,
        ]);

        $readToken = $this->user->createToken('read-token', ['read'])->plainTextToken;

        $response = $this->withHeaders(authHeaders($readToken))
            ->getJson('/api/v1/notifications/discord');

        $response->assertSuccessful();
        $response->assertJsonMissingPath('discord_webhook_url');
        expect($response->getContent())->not->toContain('hidden/secret');
    });

    test('includes encrypted secrets with read:sensitive ability for admins', function () {
        $this->team->discordNotificationSettings->update([
            'discord_webhook_url' => 'https://discord.com/api/webhooks/visible/secret-token',
            'discord_enabled' => true,
        ]);

        $sensitiveToken = $this->user->createToken('read-sensitive-token', ['read', 'read:sensitive'])->plainTextToken;

        $response = $this->withHeaders(authHeaders($sensitiveToken))
            ->getJson('/api/v1/notifications/discord');

        $response->assertSuccessful();
        $response->assertJsonFragment([
            'discord_webhook_url' => 'https://discord.com/api/webhooks/visible/secret-token',
        ]);
    });

    test('member with read token can view settings but not secrets', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        session(['currentTeam' => $this->team]);

        $this->team->emailNotificationSettings->update([
            'smtp_password' => 'super-secret-password',
            'smtp_enabled' => true,
        ]);

        $memberToken = $member->createToken('member-read', ['read'])->plainTextToken;

        $response = $this->withHeaders(authHeaders($memberToken))
            ->getJson('/api/v1/notifications/email');

        $response->assertSuccessful();
        $response->assertJsonPath('smtp_enabled', true);
        $response->assertJsonMissingPath('smtp_password');
        expect($response->getContent())->not->toContain('super-secret-password');
    });

    test('member cannot use read:sensitive token ability', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        session(['currentTeam' => $this->team]);

        $memberToken = $member->createToken('member-sensitive', ['read', 'read:sensitive'])->plainTextToken;

        $this->withHeaders(authHeaders($memberToken))
            ->getJson('/api/v1/notifications/email')
            ->assertForbidden();
    });

    test('rejects unauthenticated requests', function () {
        $this->getJson('/api/v1/notifications/email')
            ->assertStatus(401);
    });

    test('firstOrCreate restores missing channel settings', function () {
        DiscordNotificationSettings::query()->where('team_id', $this->team->id)->delete();

        expect(DiscordNotificationSettings::query()->where('team_id', $this->team->id)->exists())->toBeFalse();

        $response = $this->withHeaders(authHeaders($this->bearerToken))
            ->getJson('/api/v1/notifications/discord');

        $response->assertSuccessful();
        $response->assertJsonPath('team_id', $this->team->id);
        expect(DiscordNotificationSettings::query()->where('team_id', $this->team->id)->exists())->toBeTrue();
    });
});

describe('PATCH /api/v1/notifications/*', function () {
    test('updates email notification settings', function () {
        $response = $this->withHeaders(authHeaders($this->bearerToken))
            ->patchJson('/api/v1/notifications/email', [
                'smtp_enabled' => true,
                'smtp_from_address' => 'alerts@example.com',
                'smtp_host' => 'smtp.example.com',
                'smtp_ehlo_domain' => 'coolify.example.com',
                'smtp_port' => 587,
                'smtp_encryption' => 'starttls',
                'deployment_failure_email_notifications' => false,
            ]);

        $response->assertSuccessful();
        $response->assertJsonPath('smtp_enabled', true);
        $response->assertJsonPath('smtp_ehlo_domain', 'coolify.example.com');
        $response->assertJsonPath('deployment_failure_email_notifications', false);

        $settings = EmailNotificationSettings::query()->where('team_id', $this->team->id)->first();
        expect($settings->smtp_enabled)->toBeTrue()
            ->and($settings->smtp_from_address)->toBe('alerts@example.com')
            ->and($settings->smtp_host)->toBe('smtp.example.com')
            ->and($settings->smtp_ehlo_domain)->toBe('coolify.example.com')
            ->and($settings->smtp_port)->toBe(587)
            ->and($settings->deployment_failure_email_notifications)->toBeFalse();
    });

    test('updates the deployment commit details email setting', function () {
        $this->withHeaders(authHeaders($this->bearerToken))
            ->getJson('/api/v1/notifications/email')
            ->assertSuccessful()
            ->assertJsonPath('deployment_commit_details_email_notifications', false);

        $this->withHeaders(authHeaders($this->bearerToken))
            ->patchJson('/api/v1/notifications/email', [
                'deployment_commit_details_email_notifications' => true,
            ])
            ->assertSuccessful()
            ->assertJsonPath('deployment_commit_details_email_notifications', true);

        expect(EmailNotificationSettings::query()->where('team_id', $this->team->id)->value('deployment_commit_details_email_notifications'))->toBeTruthy();
    });

    test('validates the deployment commit details email setting', function () {
        $this->withHeaders(authHeaders($this->bearerToken))
            ->patchJson('/api/v1/notifications/email', [
                'deployment_commit_details_email_notifications' => 'not-a-boolean',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deployment_commit_details_email_notifications');
    });

    test('validates the smtp ehlo domain', function () {
        $this->withHeaders(authHeaders($this->bearerToken))
            ->patchJson('/api/v1/notifications/email', [
                'smtp_ehlo_domain' => 'not a hostname',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('smtp_ehlo_domain');
    });

    test('clears the smtp ehlo domain', function () {
        $this->team->emailNotificationSettings->update([
            'smtp_ehlo_domain' => 'coolify.example.com',
        ]);

        $this->withHeaders(authHeaders($this->bearerToken))
            ->patchJson('/api/v1/notifications/email', [
                'smtp_ehlo_domain' => null,
            ])
            ->assertSuccessful()
            ->assertJsonPath('smtp_ehlo_domain', null);

        expect($this->team->emailNotificationSettings->fresh()->smtp_ehlo_domain)->toBeNull();
    });

    test('updates discord notification settings', function () {
        $response = $this->withHeaders(authHeaders($this->bearerToken))
            ->patchJson('/api/v1/notifications/discord', [
                'discord_enabled' => true,
                'discord_webhook_url' => 'https://discord.com/api/webhooks/123/abc',
                'discord_ping_enabled' => false,
                'discord_ping_text' => '<@&123456789>',
                'deployment_success_discord_notifications' => true,
            ]);

        $response->assertSuccessful();
        $response->assertJsonPath('discord_enabled', true);
        $response->assertJsonPath('discord_ping_enabled', false);

        $settings = DiscordNotificationSettings::query()->where('team_id', $this->team->id)->first();
        expect($settings->discord_enabled)->toBeTrue()
            ->and($settings->discord_webhook_url)->toBe('https://discord.com/api/webhooks/123/abc')
            ->and($settings->discord_ping_enabled)->toBeFalse()
            ->and($settings->discord_ping_text)->toBe('<@&123456789>')
            ->and($settings->deployment_success_discord_notifications)->toBeTrue();
    });

    test('updates slack, telegram, pushover, ntfy, and webhook channels', function (string $channel, array $payload, string $modelClass, string $enabledField) {
        $response = $this->withHeaders(authHeaders($this->bearerToken))
            ->patchJson("/api/v1/notifications/{$channel}", $payload);

        $response->assertSuccessful();
        $response->assertJsonPath($enabledField, true);

        $settings = $modelClass::query()->where('team_id', $this->team->id)->first();
        expect($settings->{$enabledField})->toBeTrue();
    })->with([
        'slack' => [
            'slack',
            [
                'slack_enabled' => true,
                'slack_webhook_url' => 'https://hooks.slack.com/services/T00/B00/xxx',
                'deployment_failure_slack_notifications' => true,
            ],
            SlackNotificationSettings::class,
            'slack_enabled',
        ],
        'telegram' => [
            'telegram',
            [
                'telegram_enabled' => true,
                'telegram_token' => '123456:ABC-DEF',
                'telegram_chat_id' => '-100123',
                'deployment_failure_telegram_notifications' => true,
            ],
            TelegramNotificationSettings::class,
            'telegram_enabled',
        ],
        'pushover' => [
            'pushover',
            [
                'pushover_enabled' => true,
                'pushover_user_key' => 'user-key',
                'pushover_api_token' => 'api-token',
                'deployment_failure_pushover_notifications' => true,
            ],
            PushoverNotificationSettings::class,
            'pushover_enabled',
        ],
        'ntfy' => [
            'ntfy',
            [
                'ntfy_enabled' => true,
                'ntfy_url' => 'https://ntfy.sh',
                'ntfy_topic' => 'coolify-alerts',
                'ntfy_auth_method' => 'token',
                'ntfy_token' => 'tk_secret',
                'ntfy_priority_error_events' => 5,
                'deployment_failure_ntfy_notifications' => true,
            ],
            NtfyNotificationSettings::class,
            'ntfy_enabled',
        ],
        'webhook' => [
            'webhook',
            [
                'webhook_enabled' => true,
                'webhook_url' => 'https://example.com/hooks/coolify',
                'deployment_failure_webhook_notifications' => true,
            ],
            WebhookNotificationSettings::class,
            'webhook_enabled',
        ],
    ]);

    test('hides ntfy server, topic, and credentials without read:sensitive ability', function () {
        $this->team->ntfyNotificationSettings->update([
            'ntfy_enabled' => true,
            'ntfy_url' => 'https://ntfy.example.com/hidden-server',
            'ntfy_topic' => 'hidden-topic',
            'ntfy_token' => 'hidden-token',
            'ntfy_username' => 'hidden-user',
            'ntfy_password' => 'hidden-password',
        ]);

        $readToken = $this->user->createToken('read-token', ['read'])->plainTextToken;

        $response = $this->withHeaders(authHeaders($readToken))
            ->getJson('/api/v1/notifications/ntfy');

        $response->assertSuccessful();
        $response->assertJsonPath('ntfy_enabled', true);
        foreach (['ntfy_url', 'ntfy_topic', 'ntfy_token', 'ntfy_username', 'ntfy_password'] as $secret) {
            $response->assertJsonMissingPath($secret);
        }
        expect($response->getContent())->not->toContain('hidden-');
    });

    test('clears stored ntfy credentials when the server URL changes without new ones', function () {
        $this->team->ntfyNotificationSettings->update([
            'ntfy_url' => 'https://ntfy.example.com',
            'ntfy_topic' => 'alerts',
            'ntfy_auth_method' => 'token',
            'ntfy_token' => 'tk_stored',
            'ntfy_username' => 'stored-user',
            'ntfy_password' => 'stored-password',
        ]);

        $this->withHeaders(authHeaders($this->bearerToken))
            ->patchJson('/api/v1/notifications/ntfy', ['ntfy_url' => 'https://1.1.1.1'])
            ->assertSuccessful();

        $settings = $this->team->ntfyNotificationSettings->fresh();
        expect($settings->ntfy_url)->toBe('https://1.1.1.1')
            ->and($settings->ntfy_token)->toBeNull()
            ->and($settings->ntfy_username)->toBeNull()
            ->and($settings->ntfy_password)->toBeNull();
    });

    test('keeps ntfy credentials sent together with a new server URL', function () {
        $this->team->ntfyNotificationSettings->update([
            'ntfy_url' => 'https://ntfy.example.com',
            'ntfy_auth_method' => 'token',
            'ntfy_token' => 'tk_stored',
        ]);

        $this->withHeaders(authHeaders($this->bearerToken))
            ->patchJson('/api/v1/notifications/ntfy', ['ntfy_url' => 'https://1.1.1.1', 'ntfy_token' => 'tk_new'])
            ->assertSuccessful();

        expect($this->team->ntfyNotificationSettings->fresh()->ntfy_token)->toBe('tk_new');
    });

    test('keeps stored ntfy credentials when the server URL does not change', function () {
        $this->team->ntfyNotificationSettings->update([
            'ntfy_url' => 'https://1.1.1.1',
            'ntfy_auth_method' => 'token',
            'ntfy_token' => 'tk_stored',
        ]);

        $this->withHeaders(authHeaders($this->bearerToken))
            ->patchJson('/api/v1/notifications/ntfy', ['ntfy_url' => 'https://1.1.1.1', 'ntfy_priority_error_events' => 4])
            ->assertSuccessful();

        expect($this->team->ntfyNotificationSettings->fresh()->ntfy_token)->toBe('tk_stored');
    });

    test('rejects invalid ntfy settings with 422', function (array $payload, string $field) {
        $this->withHeaders(authHeaders($this->bearerToken))
            ->patchJson('/api/v1/notifications/ntfy', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    })->with([
        'priority above 5' => [['ntfy_priority_error_events' => 6], 'ntfy_priority_error_events'],
        'priority below 1' => [['ntfy_priority_success_events' => 0], 'ntfy_priority_success_events'],
        'unknown auth method' => [['ntfy_auth_method' => 'oauth'], 'ntfy_auth_method'],
        'topic with a slash' => [['ntfy_topic' => 'alerts/other'], 'ntfy_topic'],
        'topic with a newline' => [['ntfy_topic' => "alerts\nother"], 'ntfy_topic'],
        'token with a newline' => [['ntfy_token' => "tk_abc\nX-Injected: 1"], 'ntfy_token'],
        'username with a control character' => [['ntfy_username' => "us\x00er"], 'ntfy_username'],
    ]);

    test('rejects unknown fields with 422', function () {
        $response = $this->withHeaders(authHeaders($this->bearerToken))
            ->patchJson('/api/v1/notifications/email', [
                'smtp_enabled' => true,
                'not_a_real_field' => 'nope',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.not_a_real_field.0', 'This field is not allowed.');
    });

    test('rejects team_id mass assignment attempts', function () {
        $otherTeam = Team::factory()->create();

        $response = $this->withHeaders(authHeaders($this->bearerToken))
            ->patchJson('/api/v1/notifications/discord', [
                'discord_enabled' => true,
                'team_id' => $otherTeam->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.team_id.0', 'This field is not allowed.');
    });

    test('requires write ability', function () {
        $readToken = $this->user->createToken('read-only', ['read'])->plainTextToken;

        $response = $this->withHeaders(authHeaders($readToken))
            ->patchJson('/api/v1/notifications/email', [
                'smtp_enabled' => true,
            ]);

        $response->assertForbidden();
    });

    test('forbids members from updating notification settings', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        session(['currentTeam' => $this->team]);
        $memberToken = $member->createToken('member-write', ['read', 'write'])->plainTextToken;

        $response = $this->withHeaders(authHeaders($memberToken))
            ->patchJson('/api/v1/notifications/email', [
                'smtp_enabled' => true,
            ]);

        $response->assertForbidden();
    });

    test('rejects empty json body', function () {
        $response = $this->withHeaders(authHeaders($this->bearerToken))
            ->patchJson('/api/v1/notifications/email', []);

        $response->assertStatus(400);
    });

    test('does not leak updates across teams', function () {
        $otherTeam = Team::factory()->create();
        $otherUser = User::factory()->create();
        $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);
        session(['currentTeam' => $otherTeam]);
        $otherToken = $otherUser->createToken('other-token', ['*'])->plainTextToken;

        $this->withHeaders(authHeaders($otherToken))
            ->patchJson('/api/v1/notifications/email', [
                'smtp_enabled' => true,
                'smtp_from_address' => 'other@example.com',
            ])
            ->assertSuccessful();

        $thisTeamSettings = EmailNotificationSettings::query()->where('team_id', $this->team->id)->first();
        $otherSettings = EmailNotificationSettings::query()->where('team_id', $otherTeam->id)->first();

        expect($thisTeamSettings->smtp_enabled)->toBeFalse()
            ->and($otherSettings->smtp_enabled)->toBeTrue()
            ->and($otherSettings->smtp_from_address)->toBe('other@example.com');
    });
});
