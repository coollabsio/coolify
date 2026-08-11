<?php

it('keeps spacing around the Docker Compose editor', function () {
    $view = file_get_contents(resource_path('views/livewire/project/new/docker-compose.blade.php'));

    expect($view)
        ->toContain('class="application-settings-section-body"')
        ->not->toContain('class="application-settings-section-body p-0!"');
});

it('lets the Docker Compose editor use the available width', function () {
    $view = file_get_contents(resource_path('views/livewire/project/new/docker-compose.blade.php'));

    expect($view)
        ->toContain('class="mt-8 w-full lg:mt-3"')
        ->not->toContain('max-w-[1180px]');
});

it('shows a loading indicator while creating the service', function () {
    $view = file_get_contents(resource_path('views/livewire/project/new/docker-compose.blade.php'));

    expect($view)->toContain('<x-forms.button type="submit" wire:target="submit" isHighlighted>');
});
