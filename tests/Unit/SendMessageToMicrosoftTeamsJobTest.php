<?php

use App\Jobs\SendMessageToMicrosoftTeamsJob;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('posts a MessageCard with the theme colour stripped of its leading hash', function () {
    Http::fake(['*' => Http::response('1', 200)]);

    $job = new SendMessageToMicrosoftTeamsJob(
        new SlackMessage(
            title: 'Deployment failed',
            description: 'App **foo** failed to deploy.',
            color: SlackMessage::errorColor(),
        ),
        'https://example.webhook.office.com/webhookb2/abc'
    );

    $job->handle();

    Http::assertSent(function ($request) {
        expect($request->data())->toBe([
            '@type' => 'MessageCard',
            '@context' => 'http://schema.org/extensions',
            'themeColor' => 'ff0000',
            'summary' => 'Deployment failed',
            'sections' => [
                [
                    'activityTitle' => 'Deployment failed',
                    'text' => 'App **foo** failed to deploy.',
                    'markdown' => true,
                ],
            ],
        ]);

        return $request->url() === 'https://example.webhook.office.com/webhookb2/abc';
    });
});

it('blocks unsafe webhook URLs before sending', function (string $url) {
    Http::fake();

    $job = new SendMessageToMicrosoftTeamsJob(
        new SlackMessage(title: 'Test', description: 'Test'),
        $url
    );

    $job->handle();

    Http::assertNothingSent();
})->with([
    'loopback' => 'http://127.0.0.1/webhook',
    'cloud metadata' => 'http://169.254.169.254/',
    'ipv4-mapped ipv6 link-local' => 'http://[::ffff:169.254.169.254]/',
    'not a url' => 'not-a-url',
]);
