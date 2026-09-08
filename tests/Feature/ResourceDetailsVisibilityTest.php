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
        ->toContain('window.copyToClipboard')
        ->toContain('input-with-copy-button')
        ->toContain('copy-button')
        ->toContain('aria-label="Copy to clipboard"')
        ->toContain('title="Copy to clipboard"')
        ->toContain('class="size-[18px] text-green-500"');
});

it('uses the shared copy field for newly issued api tokens', function () {
    $blade = file_get_contents(resource_path('views/livewire/security/api-tokens.blade.php'));

    expect($blade)
        ->toContain('<x-forms.copy-button :text="session(\'token\')" />')
        ->not->toContain('navigator.clipboard.writeText(@js(session(\'token\')))');
});

it('keeps copy button padding above settings-workspace input overrides', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('.input.input-with-copy-button')
        ->toContain('.application-settings-workspace .input.input-with-copy-button')
        ->toContain('.application-settings-form .input.input-with-copy-button')
        ->toContain('padding-right: 2.5rem');
});
