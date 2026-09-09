<?php

it('shows that image tag and digest are mutually exclusive', function () {
    $view = file_get_contents(resource_path('views/livewire/project/new/docker-image.blade.php'));

    expect($view)
        ->toContain('aria-label="Tag and SHA256 digest are mutually exclusive"')
        ->toContain('>OR</span>');
});
