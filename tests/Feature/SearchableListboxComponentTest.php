<?php

use Illuminate\Support\Facades\Blade;

test('searchable listbox renders its label, search field, and options', function () {
    $html = Blade::render(<<<'BLADE'
        <x-forms.searchable-listbox id="serverTimezone" label="Server timezone"
            searchPlaceholder="Search timezones" emptyText="No matching timezone"
            :options="[
                ['value' => 'UTC', 'label' => 'UTC'],
                ['value' => 'Europe/Berlin', 'label' => 'Europe/Berlin'],
                ['value' => 'America/New_York', 'label' => 'America/New_York'],
            ]" :wire="false" value="UTC" />
    BLADE);

    expect($html)
        ->toContain('Server timezone')
        ->toContain('Search timezones')
        ->toContain('No matching timezone')
        ->toContain('Berlin')
        ->toContain('New_York');
});
