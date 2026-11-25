<?php

use App\Jobs\SendMessageToNtfyJob;
use App\Models\InstanceSettings;
use App\Models\PersonalAccessToken;
use App\Models\Team;
use App\Notifications\ApiTokenExpiringNotification;
use App\Notifications\Channels\NtfyChannel;
use App\Notifications\Dto\NtfyMessage;
use App\Notifications\Internal\GeneralNotification;
use App\Notifications\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // base_url() resolves the instance settings singleton, which lives at the id = 0 sentinel.
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], [
        'fqdn' => 'https://coolify.example.com',
    ]));
    Queue::fake();

    $this->team = Team::create([
        'name' => 'Ntfy Channel Team',
        'personal_team' => false,
        'show_boarding' => false,
    ]);
    // Assign through the model so the `encrypted` casts are applied.
    $settings = $this->team->ntfyNotificationSettings;
    $settings->ntfy_enabled = true;
    $settings->ntfy_url = 'https://ntfy.example.com';
    $settings->ntfy_topic = 'coolify-alerts';
    $settings->ntfy_auth_method = 'none';
    $settings->save();
    $this->team->refresh();
});

/**
 * Send a notification through the ntfy channel and return the dispatched job.
 */
function deliverOverNtfy(Team $team, Notification $notification): SendMessageToNtfyJob
{
    (new NtfyChannel)->send($team, $notification);

    $dispatched = null;
    Queue::assertPushed(SendMessageToNtfyJob::class, function (SendMessageToNtfyJob $job) use (&$dispatched) {
        $dispatched = $job;

        return true;
    });

    expect($dispatched->message->title)->not->toBeEmpty()
        ->and($dispatched->message->message)->not->toBeEmpty();

    return $dispatched;
}

it('has an ntfy message for every notification that has a pushover message', function () {
    $missing = collect(Finder::create()->files()->in(app_path('Notifications'))->name('*.php'))
        ->map(fn ($file) => 'App\\Notifications\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
        ->filter(fn (string $class) => class_exists($class) && method_exists($class, 'toPushover'))
        ->reject(fn (string $class) => method_exists($class, 'toNtfy'))
        ->values()
        ->all();

    expect($missing)->toBe([]);
});

it('has an ntfy setting for every per-event pushover setting', function () {
    $eventColumns = fn (string $channel) => collect(Schema::getColumnListing("{$channel}_notification_settings"))
        ->filter(fn (string $column) => str_ends_with($column, "_{$channel}_notifications"))
        ->map(fn (string $column) => str_replace("_{$channel}_notifications", '', $column))
        ->sort()
        ->values()
        ->all();

    expect(array_diff($eventColumns('pushover'), $eventColumns('ntfy')))->toBe([]);
});

it('sends to the configured server and topic with the team priority for the level', function () {
    $this->team->ntfyNotificationSettings->update(['ntfy_priority_error_events' => 4]);

    $job = deliverOverNtfy($this->team->refresh(), new GeneralNotification('Disk full', success: false));

    expect($job->ntfyUrl)->toBe('https://ntfy.example.com')
        ->and($job->ntfyTopic)->toBe('coolify-alerts')
        ->and($job->message->level)->toBe('error')
        ->and($job->message->getEffectivePriority())->toBe(4);
});

it('sends successful general notifications at info level', function () {
    $job = deliverOverNtfy($this->team, new GeneralNotification('Something happened'));

    expect($job->message->level)->toBe('info')
        ->and($job->message->message)->toBe('Something happened');
});

it('delivers api token expiring notifications over ntfy', function () {
    $token = new PersonalAccessToken([
        'name' => 'ci-token',
        'expires_at' => now()->addDay(),
    ]);

    $job = deliverOverNtfy($this->team, new ApiTokenExpiringNotification($token));

    expect($job->message->level)->toBe('warning')
        ->and($job->message->message)->toContain('ci-token');
});

it('passes only the credentials of the selected auth method', function (string $method, ?string $token, ?string $username, ?string $password) {
    // Stale values from a previously selected method must not be sent.
    $this->team->ntfyNotificationSettings->update([
        'ntfy_auth_method' => $method,
        'ntfy_token' => 'tk_stored',
        'ntfy_username' => 'stored-user',
        'ntfy_password' => 'stored-password',
    ]);

    $job = deliverOverNtfy($this->team->refresh(), new Test);

    expect($job->ntfyToken)->toBe($token)
        ->and($job->ntfyUsername)->toBe($username)
        ->and($job->ntfyPassword)->toBe($password);
})->with([
    'token' => ['token', 'tk_stored', null, null],
    'basic' => ['basic', null, 'stored-user', 'stored-password'],
    'none' => ['none', null, null, null],
]);

it('does not send when ntfy is disabled', function () {
    $this->team->ntfyNotificationSettings->update(['ntfy_enabled' => false]);

    (new NtfyChannel)->send($this->team->refresh(), new Test);

    Queue::assertNotPushed(SendMessageToNtfyJob::class);
});

it('posts the message with the matching authorization header', function (?string $token, ?string $username, ?string $password, ?string $authorization) {
    Http::fake();

    (new SendMessageToNtfyJob(new NtfyMessage('Title', 'Body', level: 'success'), 'https://1.1.1.1/', 'alerts', $token, $username, $password))->handle();

    Http::assertSent(function (Request $request) use ($authorization) {
        return $request->url() === 'https://1.1.1.1'
            && $request['topic'] === 'alerts'
            && $request['title'] === 'Title'
            && $request['priority'] === 2
            && $request->header('Authorization') === ($authorization === null ? [] : [$authorization]);
    });
})->with([
    'token' => ['tk_123', null, null, 'Bearer tk_123'],
    'basic' => [null, 'user', 'pass', 'Basic '.base64_encode('user:pass')],
    'none' => [null, null, null, null],
]);

it('blocks queued ntfy notifications to unsafe server URLs', function (string $url) {
    Http::fake();

    (new SendMessageToNtfyJob(new NtfyMessage('Title', 'Body'), $url, 'alerts'))->handle();

    Http::assertNothingSent();
})->with([
    'link-local metadata' => ['http://169.254.169.254/'],
    'IPv4-mapped link-local' => ['http://[::ffff:169.254.169.254]/'],
    'NAT64 local-use' => ['http://[64:ff9b:1::7f00:1]/'],
    'loopback' => ['http://127.0.0.1:8080/'],
]);

it('builds view actions from buttons and points localhost links at the app url', function () {
    config(['app.url' => 'https://coolify.example.com']);

    $payload = (new NtfyMessage('Title', 'Body', buttons: [
        ['text' => 'Open', 'url' => 'http://localhost/project/1'],
        ['text' => 'No url'],
    ], level: 'warning'))->toPayload('alerts');

    expect($payload['priority'])->toBe(4)
        ->and($payload['tags'])->toBe(['warning'])
        ->and($payload['actions'])->toBe([
            ['action' => 'view', 'label' => 'Open', 'url' => 'https://coolify.example.com/project/1'],
        ]);
});

it('sends at most three actions, which is the ntfy limit', function () {
    $buttons = collect(range(1, 5))->map(fn (int $i) => ['text' => "Open {$i}", 'url' => "https://coolify.example.com/{$i}"])->all();

    $payload = (new NtfyMessage('Title', 'Body', buttons: $buttons))->toPayload('alerts');

    expect($payload['actions'])->toHaveCount(3)
        ->and(array_column($payload['actions'], 'label'))->toBe(['Open 1', 'Open 2', 'Open 3']);
});

it('shortens messages above the ntfy message size limit without splitting characters', function () {
    $payload = (new NtfyMessage('Title', str_repeat('ğ', 5000)))->toPayload('alerts');

    expect(strlen($payload['message']))->toBeLessThanOrEqual(NtfyMessage::MAX_MESSAGE_BYTES)
        ->and(mb_check_encoding($payload['message'], 'UTF-8'))->toBeTrue()
        ->and($payload['message'])->toEndWith('…');
});

it('keeps short messages unchanged', function () {
    expect((new NtfyMessage('Title', 'Short body'))->toPayload('alerts')['message'])->toBe('Short body');
});

it('does not copy the ntfy server response into the failure message', function () {
    Http::fake(['*' => Http::response('echoed Authorization: Bearer tk_secret', 500)]);

    $job = new SendMessageToNtfyJob(new NtfyMessage('Title', 'Body'), 'https://1.1.1.1/', 'alerts', 'tk_secret');

    expect(fn () => $job->handle())->toThrow(function (RuntimeException $e) {
        expect($e->getMessage())->toContain('500')
            ->not->toContain('tk_secret');
    });
});
