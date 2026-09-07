<?php

test('server settings explain dedicated build server mode', function () {
    $view = file_get_contents(resource_path('views/livewire/server/show.blade.php'));

    expect($view)
        ->toContain('label="Use as a dedicated build server"')
        ->toContain('helper="Build servers compile applications but do not host deployments. Enabling this makes the server build-only."');
});
