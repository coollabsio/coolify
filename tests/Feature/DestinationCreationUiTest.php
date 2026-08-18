<?php

it('returns after creating a destination and shows submit loading state', function () {
    $component = file_get_contents(app_path('Livewire/Destination/New/Docker.php'));
    $view = file_get_contents(resource_path('views/livewire/destination/new/docker.blade.php'));

    expect($component)
        ->toContain("return redirectRoute(\$this, 'destination.show', [\$docker->uuid]);");
    expect($view)
        ->toContain('wire:submit="submit"')
        ->toContain('wire:target="submit"');
});
