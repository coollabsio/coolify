<?php

use App\Models\DiscordNotificationSettings;
use App\Models\EmailNotificationSettings;
use App\Models\InstanceSettings;
use App\Models\PushoverNotificationSettings;
use App\Models\SlackNotificationSettings;
use App\Models\Team;
use App\Models\TelegramNotificationSettings;
use App\Models\User;
use App\Models\WebhookNotificationSettings;
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
                'deployment_success_discord_notifications' => true,
            ]);

        $response->assertSuccessful();
        $response->assertJsonPath('discord_enabled', true);
        $response->assertJsonPath('discord_ping_enabled', false);

        $settings = DiscordNotificationSettings::query()->where('team_id', $this->team->id)->first();
        expect($settings->discord_enabled)->toBeTrue()
            ->and($settings->discord_webhook_url)->toBe('https://discord.com/api/webhooks/123/abc')
            ->and($settings->discord_ping_enabled)->toBeFalse()
            ->and($settings->deployment_success_discord_notifications)->toBeTrue();
    });

    test('updates slack, telegram, pushover, and webhook channels', function (string $channel, array $payload, string $modelClass, string $enabledField) {
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
