<?php

use App\Jobs\SendMessageToDiscordJob;
use App\Jobs\SendMessageToPushoverJob;
use App\Jobs\SendMessageToSlackJob;
use App\Jobs\SendMessageToTelegramJob;
use App\Jobs\SendWebhookJob;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use App\Notifications\Internal\GeneralNotification;
use App\Notifications\Test;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
    $this->team = Team::factory()->create();
});

/**
 * @return array{0: Model, 1: Closure(Model|null): object, 2: string}
 */
function deliveryFailureChannel(Team $team, string $channel): array
{
    return match ($channel) {
        'discord' => [
            tap($team->discordNotificationSettings)->update(['discord_enabled' => true, 'discord_webhook_url' => 'https://example.com/discord']),
            fn ($settings) => new SendMessageToDiscordJob(new DiscordMessage('Title', 'Description', DiscordMessage::infoColor()), 'https://example.com/discord', $settings),
            'discord_enabled',
        ],
        'slack' => [
            tap($team->slackNotificationSettings)->update(['slack_enabled' => true, 'slack_webhook_url' => 'https://example.com/slack']),
            fn ($settings) => new SendMessageToSlackJob(new SlackMessage('Title', 'Description'), 'https://example.com/slack', $settings),
            'slack_enabled',
        ],
        'telegram' => [
            tap($team->telegramNotificationSettings)->update(['telegram_enabled' => true, 'telegram_token' => 'token', 'telegram_chat_id' => '123']),
            fn ($settings) => new SendMessageToTelegramJob('Message', [], 'token', '123', null, $settings),
            'telegram_enabled',
        ],
        'pushover' => [
            tap($team->pushoverNotificationSettings)->update(['pushover_enabled' => true, 'pushover_api_token' => 'token', 'pushover_user_key' => 'user']),
            fn ($settings) => new SendMessageToPushoverJob(new PushoverMessage('Title', 'Message'), 'token', 'user', $settings),
            'pushover_enabled',
        ],
        'webhook' => [
            tap($team->webhookNotificationSettings)->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/webhook']),
            fn ($settings) => new SendWebhookJob(['event' => 'test'], 'https://example.com/webhook', $settings),
            'webhook_enabled',
        ],
    };
}

dataset('configuration errors', [
    'discord deleted webhook' => ['discord', 404, ['message' => 'Unknown Webhook', 'code' => 10015]],
    'slack archived channel' => ['slack', 410, 'channel_is_archived'],
    'telegram invalid token' => ['telegram', 401, ['ok' => false, 'description' => 'Unauthorized']],
    'telegram wrong chat' => ['telegram', 400, ['ok' => false, 'description' => 'Bad Request: chat not found']],
    'pushover invalid token' => ['pushover', 400, ['token' => 'invalid', 'errors' => ['application token is invalid'], 'status' => 0]],
    'webhook not found' => ['webhook', 404, 'Not Found'],
]);

it('disables the channel after three configuration errors in a row', function (string $channel, int $status, array|string $body) {
    [$settings, $makeJob, $enabledColumn] = deliveryFailureChannel($this->team, $channel);
    Http::fake(['*' => Http::response($body, $status)]);

    $makeJob($settings->fresh())->handle();
    $makeJob($settings->fresh())->handle();
    expect($settings->fresh()->failure_count)->toBe(2)
        ->and($settings->fresh()->{$enabledColumn})->toBeTrue();

    $makeJob($settings->fresh())->handle();
    expect($settings->fresh()->{$enabledColumn})->toBeFalse();
})->with('configuration errors');

it('retries rate limits and server errors without counting them', function (string $channel, int $status) {
    [$settings, $makeJob] = deliveryFailureChannel($this->team, $channel);
    Http::fake(['*' => Http::response('error', $status)]);

    expect(fn () => $makeJob($settings->fresh())->handle())->toThrow(RuntimeException::class);
    expect($settings->fresh()->failure_count)->toBe(0);
})->with(['discord', 'slack', 'telegram', 'pushover', 'webhook'])->with([429, 500]);

it('does not count errors caused by the message payload', function (string $channel, array|string $body) {
    [$settings, $makeJob] = deliveryFailureChannel($this->team, $channel);
    Http::fake(['*' => Http::response($body, 400)]);

    $makeJob($settings->fresh())->handle();

    expect($settings->fresh()->failure_count)->toBe(0);
})->with([
    'discord' => ['discord', ['message' => 'Invalid Form Body', 'code' => 50035]],
    'slack' => ['slack', 'invalid_payload'],
    'telegram' => ['telegram', ['ok' => false, 'description' => "Bad Request: can't parse entities"]],
    'pushover' => ['pushover', ['message' => 'cannot be longer than 1024 characters', 'status' => 0]],
    'webhook' => ['webhook', 'Bad Request'],
]);

it('resets the failure count after a successful delivery', function () {
    [$settings, $makeJob] = deliveryFailureChannel($this->team, 'discord');
    $settings->increment('failure_count', 2);
    Http::fake(['*' => Http::response('', 204)]);

    $makeJob($settings->fresh())->handle();

    expect($settings->fresh()->failure_count)->toBe(0);
});

it('resets the failure count when the user changes the settings', function () {
    [$settings] = deliveryFailureChannel($this->team, 'discord');
    $settings->increment('failure_count', 2);

    $settings->fresh()->update(['discord_webhook_url' => 'https://example.com/new-discord']);

    expect($settings->fresh()->failure_count)->toBe(0);
});

it('does not track test notifications', function () {
    deliveryFailureChannel($this->team, 'discord');
    Queue::fake();

    (new DiscordChannel)->send($this->team, new Test(channel: 'discord'));

    Queue::assertPushed(SendMessageToDiscordJob::class, fn (SendMessageToDiscordJob $job) => $job->settings === null);
});

it('tracks normal notifications', function () {
    [$settings] = deliveryFailureChannel($this->team, 'discord');
    Queue::fake();

    (new DiscordChannel)->send($this->team, new class extends Notification
    {
        public function toDiscord(): DiscordMessage
        {
            return new DiscordMessage('Title', 'Description', DiscordMessage::infoColor());
        }
    });

    Queue::assertPushed(SendMessageToDiscordJob::class, fn (SendMessageToDiscordJob $job) => $job->settings?->is($settings));
});

it('tells the team through its other enabled channels when a channel is disabled', function () {
    [$settings, $makeJob] = deliveryFailureChannel($this->team, 'discord');
    deliveryFailureChannel($this->team, 'slack');
    $settings->increment('failure_count', 2);
    Http::fake(['*' => Http::response(['message' => 'Unknown Webhook', 'code' => 10015], 404)]);
    NotificationFacade::fake();

    $makeJob($settings->fresh())->handle();

    NotificationFacade::assertSentTo($this->team, GeneralNotification::class, function (GeneralNotification $notification, array $channels) {
        return $channels === [SlackChannel::class]
            && str_contains($notification->message, 'Discord notifications were disabled');
    });
});
