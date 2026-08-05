<?php

use Illuminate\Support\Facades\Blade;

it('renders section helper copy as a visible description instead of an info icon', function () {
    $html = Blade::render(<<<'BLADE'
        <x-application.settings-section title="Primary server" helper="The server and network used by this resource.">
            Content
        </x-application.settings-section>
    BLADE);

    expect($html)
        ->toContain('Primary server')
        ->toContain('The server and network used by this resource.')
        ->not->toContain('aria-label="More information"');
});
