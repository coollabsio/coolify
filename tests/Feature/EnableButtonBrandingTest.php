<?php

it('uses highlighted styling for enable toggle actions', function () {
    $views = [
        resource_path('views/components/notification/channel-actions.blade.php'),
        resource_path('views/livewire/settings-oauth.blade.php'),
        resource_path('views/livewire/project/shared/scheduled-task/show.blade.php'),
    ];

    foreach ($views as $view) {
        expect(file_get_contents($view))->toContain('isHighlighted');
    }

    $terminalAccess = file_get_contents(resource_path('views/livewire/server/security/terminal-access.blade.php'));

    expect($terminalAccess)->toContain(':isHighlightedButton="!$isTerminalEnabled"');
});
