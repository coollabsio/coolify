<?php

use App\Notifications\Server\HetznerDeletionFailed;
use Mockery;

afterEach(function () {
    Mockery::close();
});

it('can be instantiated with correct properties', function () {
    $notification = new HetznerDeletionFailed(
        hetznerServerId: 12345,
        teamId: 1,
        errorMessage: 'Hetzner API error: Server not found'
    );

    expect($notification)->toBeInstanceOf(HetznerDeletionFailed::class)
        ->and($notification->hetznerServerId)->toBe(12345)
        ->and($notification->teamId)->toBe(1)
        ->and($notification->errorMessage)->toBe('Hetzner API error: Server not found');
});

it('uses hetzner_deletion_failed event for channels', function () {
    $notification = new HetznerDeletionFailed(
        hetznerServerId: 12345,
        teamId: 1,
        errorMessage: 'Test error'
    );

    $mockNotifiable = Mockery::mock();
    $mockNotifiable->shouldReceive('getEnabledChannels')
        ->with('hetzner_deletion_failed')
        ->once()
        ->andReturn([]);

    $channels = $notification->via($mockNotifiable);

    expect($channels)->toBeArray();
});

it('generates correct mail content', function () {
    $notification = new HetznerDeletionFailed(
        hetznerServerId: 67890,
        teamId: 1,
        errorMessage: 'Connection timeout'
    );

    $mail = $notification->toMail();

    expect($mail->subject)->toBe('Coolify: [ACTION REQUIRED] Failed to delete Hetzner server #67890')
        ->and($mail->view)->toBe('emails.hetzner-deletion-failed')
        ->and($mail->viewData['hetznerServerId'])->toBe(67890)
        ->and($mail->viewData['errorMessage'])->toBe('Connection timeout');
});

it('generates correct discord content', function () {
    $notification = new HetznerDeletionFailed(
        hetznerServerId: 11111,
        teamId: 1,
        errorMessage: 'API rate limit exceeded'
    );

    $discord = $notification->toDiscord();

    expect($discord->title)->toContain('Failed to delete Hetzner server')
        ->and($discord->description)->toContain('#11111')
        ->and($discord->description)->toContain('API rate limit exceeded')
        ->and($discord->description)->toContain('may still exist in your Hetzner Cloud account');
});

it('generates correct telegram content', function () {
    $notification = new HetznerDeletionFailed(
        hetznerServerId: 22222,
        teamId: 1,
        errorMessage: 'Invalid token'
    );

    $telegram = $notification->toTelegram();

    expect($telegram)->toBeArray()
        ->and($telegram)->toHaveKey('message')
        ->and($telegram['message'])->toContain('#22222')
        ->and($telegram['message'])->toContain('Invalid token')
        ->and($telegram['message'])->toContain('ACTION REQUIRED');
});

it('generates correct pushover content', function () {
    $notification = new HetznerDeletionFailed(
        hetznerServerId: 33333,
        teamId: 1,
        errorMessage: 'Network error'
    );

    $pushover = $notification->toPushover();

    expect($pushover->title)->toBe('Hetzner Server Deletion Failed')
        ->and($pushover->level)->toBe('error')
        ->and($pushover->message)->toContain('#33333')
        ->and($pushover->message)->toContain('Network error');
});

it('generates correct slack content', function () {
    $notification = new HetznerDeletionFailed(
        hetznerServerId: 44444,
        teamId: 1,
        errorMessage: 'Permission denied'
    );

    $slack = $notification->toSlack();

    expect($slack->title)->toContain('Hetzner Server Deletion Failed')
        ->and($slack->description)->toContain('#44444')
        ->and($slack->description)->toContain('Permission denied');
});
