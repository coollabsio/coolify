<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

beforeEach(function () {
    $errors = new ViewErrorBag;
    $errors->put('default', new MessageBag);
    view()->share('errors', $errors);
});

it('keeps the resource details helper text visible below the modal header', function () {
    $html = view('livewire.project.shared.resource-details', [
        'resource' => (object) [
            'name' => 'Crash Loop Example',
            'uuid' => 'crashloop',
        ],
        'environment_uuid' => null,
        'environment_name' => null,
        'project_uuid' => null,
        'project_name' => null,
        'server_uuid' => null,
        'server_name' => null,
        'stack_applications' => [],
        'stack_databases' => [],
    ])->render();

    expect($html)
        ->toContain('Identifiers for this resource. Read-only')
        ->toContain('pt-1')
        ->not->toContain('-mt-4');
});

it('renders copy fields as visible readonly controls with an accessible copy action', function () {
    $html = Blade::render('<x-forms.copy-button label="UUID" text="crashloop" />');

    expect($html)
        ->toContain('label class="flex gap-1 items-center mb-1 text-sm font-medium text-black dark:text-white"')
        ->toContain('readonly')
        ->toContain('class="input pr-11 bg-white dark:bg-coolgray-100 dark:read-only:bg-coolgray-100 dark:read-only:text-white"')
        ->toContain('aria-label="Copy to clipboard"')
        ->toContain('title="Copy to clipboard"')
        ->toContain('rounded-sm p-1.5 text-neutral-500 transition-colors hover:text-neutral-700 focus-visible:ring-2 focus-visible:ring-coollabs focus-visible:ring-offset-2 dark:text-neutral-400 dark:hover:text-white dark:focus-visible:ring-warning dark:focus-visible:ring-offset-base')
        ->toContain('class="w-5 h-5 text-green-500"');
});
