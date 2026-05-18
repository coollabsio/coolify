<?php

use App\Jobs\ApiTokenExpirationWarningJob;
use App\Models\PersonalAccessToken;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ApiTokenExpiringNotification;
use Carbon\Carbon;
use Illuminate\Contracts\Notifications\Dispatcher;
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

function createTokenExpiring(User $user, Team $team, ?Carbon $expiresAt, ?Carbon $warningSentAt = null): PersonalAccessToken
{
    $plain = $user->createToken('t-'.uniqid(), ['read'], $expiresAt);
    $token = $plain->accessToken;
    $token->team_id = $team->id;
    $token->api_token_expiration_warning_sent_at = $warningSentAt;
    $token->save();

    return $token->fresh();
}

describe('ApiTokenExpirationWarningJob', function () {
    test('notifies team when token expires within 24h', function () {
        $token = createTokenExpiring($this->user, $this->team, now()->addHours(23));

        (new ApiTokenExpirationWarningJob)->handle();

        Notification::assertSentTo($this->team, ApiTokenExpiringNotification::class);
        expect($token->fresh()->api_token_expiration_warning_sent_at)->not->toBeNull();
    });

    test('does not mark token as warned when notification fails', function () {
        $token = createTokenExpiring($this->user, $this->team, now()->addHours(23));
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Notification failed'));

        $this->app->instance(Dispatcher::class, $dispatcher);

        expect(fn () => (new ApiTokenExpirationWarningJob)->handle())
            ->toThrow(RuntimeException::class, 'Notification failed');

        expect($token->fresh()->api_token_expiration_warning_sent_at)->toBeNull();
    });

    test('database marker prevents duplicate warnings on repeat runs', function () {
        createTokenExpiring($this->user, $this->team, now()->addHours(12));

        (new ApiTokenExpirationWarningJob)->handle();
        (new ApiTokenExpirationWarningJob)->handle();

        Notification::assertSentToTimes($this->team, ApiTokenExpiringNotification::class, 1);
    });

    test('database marker prevents duplicate warnings after cache is flushed', function () {
        createTokenExpiring($this->user, $this->team, now()->addHours(12));

        (new ApiTokenExpirationWarningJob)->handle();

        Cache::flush();

        (new ApiTokenExpirationWarningJob)->handle();

        Notification::assertSentToTimes($this->team, ApiTokenExpiringNotification::class, 1);
    });

    test('skips tokens that already have an expiration warning marker', function () {
        createTokenExpiring($this->user, $this->team, now()->addHours(12), now()->subHour());

        (new ApiTokenExpirationWarningJob)->handle();

        Notification::assertNothingSent();
    });

    test('notifies once for each unmarked expiring token', function () {
        createTokenExpiring($this->user, $this->team, now()->addHours(12));
        createTokenExpiring($this->user, $this->team, now()->addHours(23));

        (new ApiTokenExpirationWarningJob)->handle();

        Notification::assertSentToTimes($this->team, ApiTokenExpiringNotification::class, 2);
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
