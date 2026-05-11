<?php

use App\Jobs\ApiTokenExpirationWarningJob;
use App\Models\PersonalAccessToken;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ApiTokenExpiringNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->team->emailNotificationSettings()->update(['use_instance_email_settings' => true]);
    $this->team->discordNotificationSettings()->update([
        'discord_enabled' => true,
        'discord_webhook_url' => 'https://discord.com/api/webhooks/fake/fake',
    ]);

    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);

    Cache::flush();
    Notification::fake();
});

function createTokenExpiring(User $user, Team $team, ?Carbon $expiresAt): PersonalAccessToken
{
    $plain = $user->createToken('t-'.uniqid(), ['read'], $expiresAt);
    $token = $plain->accessToken;
    $token->team_id = $team->id;
    $token->save();

    return $token->fresh();
}

describe('ApiTokenExpirationWarningJob', function () {
    test('notifies team when token expires within 24h', function () {
        createTokenExpiring($this->user, $this->team, now()->addHours(23));

        (new ApiTokenExpirationWarningJob)->handle();

        Notification::assertSentTo($this->team, ApiTokenExpiringNotification::class);
    });

    test('rate limiter prevents duplicate warnings on repeat runs', function () {
        createTokenExpiring($this->user, $this->team, now()->addHours(12));

        (new ApiTokenExpirationWarningJob)->handle();
        (new ApiTokenExpirationWarningJob)->handle();

        Notification::assertSentToTimes($this->team, ApiTokenExpiringNotification::class, 1);
    });

    test('skips tokens expiring more than 24h out', function () {
        createTokenExpiring($this->user, $this->team, now()->addDays(3));

        (new ApiTokenExpirationWarningJob)->handle();

        Notification::assertNothingSent();
    });

    test('skips already-expired tokens', function () {
        createTokenExpiring($this->user, $this->team, now()->subHour());

        (new ApiTokenExpirationWarningJob)->handle();

        Notification::assertNothingSent();
    });

    test('skips tokens with null expires_at', function () {
        createTokenExpiring($this->user, $this->team, null);

        (new ApiTokenExpirationWarningJob)->handle();

        Notification::assertNothingSent();
    });
});
