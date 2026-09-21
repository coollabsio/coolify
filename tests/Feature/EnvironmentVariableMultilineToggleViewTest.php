<?php

it('uses Alpine entangle to switch add value field immediately when multiline is enabled', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/add.blade.php'));

    expect($view)
        ->toContain('x-data="{ isMultiline: $wire.entangle(\'is_multiline\') }"')
        ->toContain('<template x-if="isMultiline">')
        ->toContain('<template x-if="!isMultiline">')
        ->toContain('id="is_multiline"')
        ->toContain('<x-forms.textarea id="value" label="Value" required class="font-sans" spellcheck />')
        ->toContain('wire:key="env-value-textarea"')
        ->toContain('wire:key="env-value-input"');
});

it('uses distinct keyed branches for the edit value field modes', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/show.blade.php'));

    expect($view)
        ->toContain('wire:key="env-show-value-textarea-{{ $env->id }}"')
        ->toContain('wire:key="env-show-value-input-{{ $env->id }}"');
});

it('lazy-loads decrypted values only when opening the edit modal', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/show.blade.php'));

    expect($view)
        ->toContain('wire:click="loadValues"')
        ->toContain('wireOpen="editorOpen"')
        ->toContain('$valuesLoaded')
        ->toContain('Loading value...');

    expect($view)->toContain('<x-forms.input loading loadingText="Loading value..."');
});

it('keeps the environment variable delete button compact', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/show.blade.php'));

    expect($view)->not->toContain('buttonFullWidth="true"');
});

it('uses sans font for the developer bulk environment variable editor', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/all.blade.php'));

    expect($view)
        ->toContain('class="whitespace-pre-wrap font-sans"')
        ->not->toContain('wire:model="variables" monospace')
        ->not->toContain('wire:model="variablesPreview" monospace');
});

it('loads environment variables asynchronously after first paint', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/all.blade.php'));

    expect($view)
        ->toContain('wire:init="loadEnvironmentVariables"')
        ->toContain('Loading environment variables...');
});

it('renders a single no results message for empty environment variable searches', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/all.blade.php'));

    expect($view)
        ->toContain('@if ($this->isSearchActive && $totalRows === 0)')
        ->toContain('No environment variables found');
});

it('paginates a unified environment variable table instead of loading every row', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/all.blade.php'));

    expect($view)
        ->toContain('$this->environmentVariablePageRows')
        ->toContain('nextEnvironmentVariablePage')
        ->not->toContain('@forelse ($this->environmentVariables as $env)');
});
