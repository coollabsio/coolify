<?php

use Illuminate\Support\Facades\Blade;

it('uses the large modal treatment for the compose editor', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/stack-form.blade.php'));
    $modal = file_get_contents(resource_path('views/components/modal-input.blade.php'));

    expect($view)
        ->toContain('title="Docker Compose"')
        ->toContain(':isLarge="true"')
        ->toContain('<x-slot:headerActions>')
        ->toContain("\$dispatch('compose-preview-toggle')")
        ->toContain("\$dispatch('compose-save')")
        ->toContain('@compose-save-finished.window="saving = false"')
        ->toContain('@compose-validate-finished.window="validating = false"')
        ->toContain('@click="validating = true; $dispatch(\'compose-validate\')"')
        ->toContain('x-bind:disabled="validating"')
        ->toContain('<x-loading-on-button x-show="validating" x-cloak />')
        ->toContain('<x-loading-on-button x-show="saving" x-cloak />')
        ->toContain('x-bind:disabled="saving"')
        ->not->toContain('name="refresh"')
        ->not->toContain("saving ? 'Saving...' : 'Save changes'")
        ->not->toContain(' :disabled="saving"')
        ->toContain('Preview generated Compose')
        ->toContain('Back to source Compose')
        ->toContain('Save changes')
        ->toContain('isHighlighted');

    expect($modal)->toContain('lg:w-[95vw]! lg:max-w-7xl!');
});

it('renders the compose editor with clear guidance settings and actions', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/edit-compose.blade.php'));

    expect($view)
        ->toContain('<x-callout type="info" title="Volume names">')
        ->not->toContain('View the final names')
        ->toContain('Use plain-text editor')
        ->toContain('min-h-[24rem]')
        ->toContain('@compose-preview-toggle.window')
        ->toContain('@compose-save.window')
        ->toContain('@compose-validate.window="$wire.validateCompose().finally(() => $dispatch(\'compose-validate-finished\'))"')
        ->not->toContain("finally(() => \$dispatch('compose-save-finished'))")
        ->not->toContain('sticky bottom-0')
        ->not->toContain('Cancel')
        ->not->toContain('Show Normal Textarea')
        ->not->toContain('Show Deployable Compose');
});

it('keeps the compose modal usable on mobile screens', function () {
    $stackForm = file_get_contents(resource_path('views/livewire/project/service/stack-form.blade.php'));
    $editor = file_get_contents(resource_path('views/livewire/project/service/edit-compose.blade.php'));
    $modal = file_get_contents(resource_path('views/components/modal-input.blade.php'));

    expect($modal)
        ->toContain('justify-center p-2')
        ->toContain('sm:p-4')
        ->toContain('flex-wrap! sm:flex-nowrap!')
        ->toContain('order-3 w-full sm:order-none sm:w-auto')
        ->toContain('order-2 sm:order-none')
        ->toContain("'mt-2 sm:mt-0' => isset(\$headerActions)");

    expect($stackForm)
        ->toContain('w-full items-center gap-2 overflow-x-auto sm:w-auto');

    expect($editor)
        ->toContain('flex-col items-stretch')
        ->toContain('sm:flex-row sm:flex-wrap sm:items-center');
});

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
