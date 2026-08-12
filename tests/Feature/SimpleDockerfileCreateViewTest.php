<?php

it('shows a loading indicator while creating the application', function () {
    $view = file_get_contents(resource_path('views/livewire/project/new/simple-dockerfile.blade.php'));

    expect($view)->toContain('<x-forms.button type="submit" wire:target="submit" isHighlighted>');
});
