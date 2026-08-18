<?php

use App\Jobs\SendMessageToDiscordJob;
use App\Jobs\SendMessageToSlackJob;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('blocks queued Slack notifications to IPv4-mapped link-local URLs', function () {
    Http::fake();

    $job = new SendMessageToSlackJob(
        new SlackMessage('Test', 'Description'),
        'http://[::ffff:169.254.169.254]/'
    );

    $job->handle();

    Http::assertNothingSent();
});

it('blocks queued Discord notifications to IPv4-mapped link-local URLs', function () {
    Http::fake();

    $job = new SendMessageToDiscordJob(
        new DiscordMessage('Test', 'Description', DiscordMessage::infoColor()),
        'http://[::ffff:169.254.169.254]/'
    );

    $job->handle();

    Http::assertNothingSent();
});
