<?php

it('shows a loading indicator while database public access is changing', function () {
    $databaseTypes = [
        'clickhouse',
        'dragonfly',
        'keydb',
        'mariadb',
        'mongodb',
        'mysql',
        'postgresql',
        'redis',
    ];

    foreach ($databaseTypes as $databaseType) {
        $generalSettings = file_get_contents(resource_path("views/livewire/project/database/{$databaseType}/general.blade.php"));

        expect($generalSettings)
            ->toContain('<x-table.loading target="instantSave" text="Updating public access..." />')
            ->toContain("'label' => blank(\$publicPort) ? 'Public through TCP proxy (set public port first)' : 'Public through TCP proxy'")
            ->toContain("'disabled' => blank(\$publicPort)")
            ->toContain('wire:key="public-access-{{ $publicPort ?: \'unset\' }}"')
            ->not->toContain('x-data="{ publicPort:')
            ->not->toContain('x-effect="options[1].disabled')
            ->not->toContain('id="publicPort" x-on:input=')
            ->not->toContain('id="publicPort" live');
    }
});
