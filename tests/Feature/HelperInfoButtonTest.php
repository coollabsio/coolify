<?php

use Illuminate\Support\Facades\Blade;

test('helper popup renders when the component is used next to a listbox label', function () {
    $html = Blade::render(<<<'BLADE'
        <x-forms.listbox id="serverTimezone" label="Server timezone"
            helper="Used for backup schedules." :options="[
                ['value' => 'UTC', 'label' => 'UTC'],
            ]" :wire="false" value="UTC" />
    BLADE);

    expect($html)
        ->toContain('Server timezone')
        ->toContain('Used for backup schedules.')
        ->toContain('type="button"')
        ->toContain('aria-label="More information"')
        ->toContain('serverTimezone-trigger')
        // Helper button must not be nested inside the label[for=trigger] element.
        ->not->toMatch('/<label[^>]*for="serverTimezone-trigger"[^>]*>[\s\S]*aria-label="More information"[\s\S]*<\/label>/');
});
