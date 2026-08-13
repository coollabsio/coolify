<?php

use Illuminate\Support\Facades\Blade;

it('renders section helper copy as a tooltip on the underlined title', function () {
    $html = Blade::render(<<<'BLADE'
        <x-application.settings-section title="Primary server" helper="The server and network used by this resource.">
            Content
        </x-application.settings-section>
    BLADE);

    expect($html)
        ->toContain('Primary server')
        ->toContain('The server and network used by this resource.')
        ->toContain('aria-label="More information about Primary server"')
        ->toContain('class="underline underline-offset-4"')
        ->toContain('underline-offset-4')
        ->not->toMatch('/<p[^>]*>\s*The server and network used by this resource\.\s*<\/p>/');
});
