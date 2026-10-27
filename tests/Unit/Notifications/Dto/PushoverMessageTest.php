<?php

use App\Notifications\Dto\PushoverMessage;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

it('builds the payload without writing to the log', function () {
    $logged = [];
    Log::listen(function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message;
    });

    $payload = (new PushoverMessage(
        title: 'Deployment failed',
        message: 'Your deployment failed.',
        buttons: [['text' => 'Open', 'url' => 'https://coolify.example.com/deployments/1']],
        level: 'error',
    ))->toPayload('app-token', 'user-key');

    expect($payload)
        ->token->toBe('app-token')
        ->user->toBe('user-key')
        ->and($logged)->toBeEmpty();
});
