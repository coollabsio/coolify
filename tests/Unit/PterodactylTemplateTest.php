<?php

it('keeps Pterodactyl service templates enabled with a panel image that includes the mysql client', function () {
    foreach (['pterodactyl-panel.yaml', 'pterodactyl-with-wings.yaml'] as $templateFile) {
        $compose = file_get_contents(__DIR__.'/../../templates/compose/'.$templateFile);

        expect($compose)->not->toContain('# ignore: true');

        preg_match('/ghcr\.io\/pterodactyl\/panel:v(?<version>\d+\.\d+\.\d+)/', $compose, $matches);
        $panelVersion = $matches['version'] ?? null;

        expect($panelVersion)->not->toBeNull();
        expect(version_compare($panelVersion, '1.12.2', '>='))->toBeTrue();
    }
});
