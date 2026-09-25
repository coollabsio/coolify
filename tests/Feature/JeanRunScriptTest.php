<?php

it('uses the jean launcher in the run configuration', function () {
    $config = json_decode(file_get_contents(base_path('jean.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($config['scripts']['run'])->toBe('./scripts/dev run')
        ->and($config['scripts']['teardown'])->toBe('./scripts/dev teardown')
        ->and($config['ports'][0]['port'])->toBe(8000);
});
