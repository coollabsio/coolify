<?php

use App\Jobs\SendWebhookJob;
use App\Models\InstanceSettings;
use App\Models\PersonalAccessToken;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\ApiTokenExpiringNotification;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\Internal\GeneralNotification;
use App\Notifications\Server\ForceDisabled;
use App\Notifications\Server\ForceEnabled;
use App\Notifications\SslExpirationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // base_url() resolves the instance settings singleton, which lives at the id = 0 sentinel.
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], [
        'fqdn' => 'https://coolify.example.com',
    ]));
    Queue::fake();

    $this->team = Team::create([
        'name' => 'Webhook Channel Team',
        'personal_team' => false,
        'show_boarding' => false,
    ]);
    // Assign through the model so the `encrypted` cast on webhook_url is applied.
    $settings = $this->team->webhookNotificationSettings;
    $settings->webhook_enabled = true;
    $settings->webhook_url = 'https://webhook.example.com/coolify';
    $settings->save();
    $this->team->refresh();
});

/**
 * Send a notification through the webhook channel and return the dispatched payload.
 *
 * @return array<string, mixed>
 */
function deliverOverWebhook(Team $team, Notification $notification): array
{
    expect($notification->via($team))->toContain(WebhookChannel::class);

    (new WebhookChannel)->send($team, $notification);

    $payload = null;
    Queue::assertPushed(SendWebhookJob::class, function (SendWebhookJob $job) use (&$payload) {
        $payload = $job->payload;

        return true;
    });

    expect($payload)->toBeArray()
        ->and($payload['success'])->toBeBool()
        ->and($payload['message'])->toBeString()->not->toBeEmpty();

    return $payload;
}

it('delivers ssl certificate renewal notifications over the webhook channel', function () {
    $payload = deliverOverWebhook(
        $this->team,
        new SslExpirationNotification([(object) ['name' => 'my-application']])
    );

    expect($payload['event'])->toBe('ssl_certificate_renewal')
        ->and($payload['resources'])->toBe(['my-application']);
});

it('delivers api token expiring notifications over the webhook channel', function () {
    $token = new PersonalAccessToken([
        'name' => 'ci-token',
        'expires_at' => now()->addDay(),
    ]);

    $payload = deliverOverWebhook($this->team, new ApiTokenExpiringNotification($token));

    expect($payload['event'])->toBe('api_token_expiring')
        ->and($payload['token_name'])->toBe('ci-token');
});

it('delivers server force enabled notifications over the webhook channel', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    $payload = deliverOverWebhook($this->team, new ForceEnabled($server));

    expect($payload['event'])->toBe('server_force_enabled')
        ->and($payload['success'])->toBeTrue()
        ->and($payload['server_uuid'])->toBe($server->uuid);
});

it('delivers server force disabled notifications over the webhook channel', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    $payload = deliverOverWebhook($this->team, new ForceDisabled($server));

    expect($payload['event'])->toBe('server_force_disabled')
        ->and($payload['success'])->toBeFalse()
        ->and($payload['server_uuid'])->toBe($server->uuid);
});

it('delivers general notifications over the webhook channel', function () {
    $payload = deliverOverWebhook($this->team, new GeneralNotification('Something happened'));

    expect($payload['event'])->toBe('general')
        ->and($payload['message'])->toBe('Something happened');
});
