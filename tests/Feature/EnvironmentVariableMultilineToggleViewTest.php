<?php

it('uses Alpine entangle to switch add value field immediately when multiline is enabled', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/add.blade.php'));

    expect($view)
        ->toContain('x-data="{ isMultiline: $wire.entangle(\'is_multiline\') }"')
        ->toContain('<template x-if="isMultiline">')
        ->toContain('<template x-if="!isMultiline">')
        ->toContain('x-model="isMultiline"')
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

it('uses sans font for the developer bulk environment variable editor', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/all.blade.php'));

    expect($view)
        ->toContain('class="whitespace-pre-wrap font-sans"')
        ->not->toContain('wire:model="variables" monospace')
        ->not->toContain('wire:model="variablesPreview" monospace');
});

it('renders the environment variable search field above the production title', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/all.blade.php'));

    expect(strpos($view, 'aria-label="Search environment variables"'))
        ->toBeLessThan(strpos($view, '<h3>Production Environment Variables</h3>'));
});

it('renders a single no results message for empty environment variable searches', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/all.blade.php'));

    expect($view)
        ->toContain('@if ($this->isSearchActive && ! $this->hasEnvironmentVariables)')
        ->toContain('<div>No environment variables found.</div>')
        ->toContain('@else');
});

it('only renders the production section when production variables are visible', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/all.blade.php'));

    expect($view)
        ->toContain('@if ($this->environmentVariables->isNotEmpty() || $this->hardcodedEnvironmentVariables->isNotEmpty())')
        ->not->toContain('@forelse ($this->environmentVariables as $env)')
        ->not->toContain('@empty');
});
