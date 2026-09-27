<?php

use Illuminate\Support\Facades\Blade;

it('keeps the save button loading until the parent compose save finishes', function () {
    $component = file_get_contents(app_path('Livewire/Project/Service/StackForm.php'));

    expect($component)
        ->toContain('public function saveCompose($raw)')
        ->toContain("\$this->dispatch('compose-save-finished')");
});

it('does not show a saving notification for compose changes', function () {
    $component = file_get_contents(app_path('Livewire/Project/Service/EditCompose.php'));

    expect($component)->not->toContain("\$this->dispatch('info', 'Saving new docker compose...')");
});

it('keeps the saving state as an Alpine button binding', function () {
    $html = Blade::render('<x-forms.button x-bind:disabled="saving">Save changes</x-forms.button>');

    expect($html)->toContain('x-bind:disabled="saving"');
});
