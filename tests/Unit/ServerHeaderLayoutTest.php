<?php

test('server header renders subtitle before status badges', function () {
    $navbar = file_get_contents(__DIR__.'/../../resources/views/livewire/server/navbar.blade.php');

    $titlePosition = strpos($navbar, '<h1>Server</h1>');
    $subtitlePosition = strpos($navbar, 'data-testid="server-subtitle"');
    $statusPosition = strpos($navbar, 'data-testid="server-status-summary"');

    expect($navbar)->not->toContain('class="subtitle"')
        ->not->toContain('text-neutral-300')
        ->toContain('text-neutral-600 dark:text-neutral-400');

    expect($titlePosition)->not->toBeFalse()
        ->and($subtitlePosition)->not->toBeFalse()
        ->and($statusPosition)->not->toBeFalse()
        ->and($titlePosition)->toBeLessThan($subtitlePosition)
        ->and($subtitlePosition)->toBeLessThan($statusPosition);
});
