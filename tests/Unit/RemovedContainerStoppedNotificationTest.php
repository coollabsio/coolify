<?php

it('does not keep the unreliable generic stopped container notification path', function () {
    $statusAction = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');
    $telegramChannel = file_get_contents(__DIR__.'/../../app/Notifications/Channels/TelegramChannel.php');

    expect($statusAction)->not->toContain('ContainerStopped')
        ->and($telegramChannel)->not->toContain('ContainerStopped')
        ->and(file_exists(__DIR__.'/../../app/Notifications/Container/ContainerStopped.php'))->toBeFalse()
        ->and(file_exists(__DIR__.'/../../resources/views/emails/container-stopped.blade.php'))->toBeFalse();
});
