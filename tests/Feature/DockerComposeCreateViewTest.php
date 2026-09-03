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

it('disables create while a compose save is in flight', function () {
    $view = file_get_contents(resource_path('views/livewire/project/new/docker-compose.blade.php'));
    $component = file_get_contents(app_path('Livewire/Project/New/DockerCompose.php'));

    expect($view)
        ->toContain('x-data="{ creating: false }"')
        ->toContain('x-bind:disabled="creating"')
        ->toContain("@click=\"if (creating) { \$event.preventDefault(); return }; creating = true\"")
        ->toContain('@compose-create-finished.window="creating = false"')
        ->toContain('wire:target="submit"')
        ->toContain('<x-loading-on-button x-show="creating" x-cloak />');

    expect($component)
        ->toContain('public bool $isSubmitting = false;')
        ->toContain('if ($this->isSubmitting) {')
        ->toContain("\$this->dispatch('compose-create-finished')");
});
