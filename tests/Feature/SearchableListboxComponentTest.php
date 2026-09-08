<?php

use Illuminate\Support\Facades\Blade;

test('searchable listbox renders search field and filters options client-side', function () {
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
        ->toContain('serverTimezone-trigger')
        ->toContain('x-ref="search"')
        ->toContain('left-3 size-3')
        ->toContain('get filtered()')
        ->toContain('searchable-listbox-panel')
        ->toContain('Berlin')
        ->toContain('New_York');
});

test('server timezone fields use the searchable listbox component', function () {
    $localhost = file_get_contents(resource_path('views/livewire/server/partials/localhost-general.blade.php'));
    $show = file_get_contents(resource_path('views/livewire/server/show.blade.php'));

    expect($localhost)
        ->toContain('x-forms.searchable-listbox id="serverTimezone"')
        ->toContain('searchPlaceholder="Search timezones"')
        ->not->toContain('<x-forms.listbox id="serverTimezone"');

    expect($show)
        ->toContain('x-forms.searchable-listbox id="serverTimezone"')
        ->toContain('searchPlaceholder="Search timezones"')
        ->not->toMatch('/<x-forms\.listbox id="serverTimezone"/');
});

test('searchable listbox keeps the helper outside the label association', function () {
    $html = Blade::render(<<<'BLADE'
        <x-forms.searchable-listbox id="tz" label="Timezone"
            helper="Used for cron jobs." searchPlaceholder="Search"
            :options="[['value' => 'UTC', 'label' => 'UTC']]" :wire="false" value="UTC" />
    BLADE);

    expect($html)
        ->toContain('Used for cron jobs.')
        ->toContain('aria-label="More information"')
        ->not->toMatch('/<label[^>]*for="tz-trigger"[^>]*>[\s\S]*aria-label="More information"[\s\S]*<\/label>/');
});

test('searchable listbox serializes change handlers', function () {
    $listbox = file_get_contents(resource_path('views/components/forms/searchable-listbox.blade.php'));

    expect($listbox)
        ->toContain('saving: false')
        ->toContain('async choose(option)')
        ->toContain('if (this.saving || option.disabled)')
        ->toContain('await this.$wire.')
        ->toContain("'pointer-events-none opacity-70': saving")
        ->toContain('@elseif ($live && ! $onChange) @entangle($id).live');
});
