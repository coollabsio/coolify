<?php

it('renders the official DigitalOcean logo path', function () {
    $contents = file_get_contents(__DIR__.'/../../resources/views/components/digital-ocean-icon.blade.php');

    expect($contents)
        ->toContain('viewBox="0 0 24 24"')
        ->toContain('M12.04 0C5.408-.02.005 5.37.005 11.992h4.638')
        ->toContain('DigitalOcean');
});

it('does not use the old custom DigitalOcean icon in Blade views', function () {
    $oldIconPaths = [
        'M100 42c31.5 0 57 25.5 57 57s-25.5 57-57 57H72v-36h28c11.6 0 21-9.4 21-21s-9.4-21-21-21-21 9.4-21 21H43c0-31.5 25.5-57 57-57z',
        'M43 120h36v36H43zM79 156h28v28H79zM43 156h28v28H43z',
    ];

    $bladeFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../../resources/views')
    );

    foreach ($bladeFiles as $bladeFile) {
        if (! $bladeFile->isFile() || $bladeFile->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($bladeFile->getPathname());

        foreach ($oldIconPaths as $oldIconPath) {
            expect($contents)
                ->not->toContain($oldIconPath, $bladeFile->getPathname().' contains the old DigitalOcean icon.');
        }
    }
});
