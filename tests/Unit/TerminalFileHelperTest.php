<?php

use App\Helpers\TerminalFileHelper;

it('generates and parses terminal filenames with alphanumeric extensions', function () {
    $filename = TerminalFileHelper::generateFilename('backup.dump.sql', time() + 3600, 42, 'container-uuid-1234');

    $metadata = TerminalFileHelper::parseFilename($filename);

    expect($metadata)
        ->not->toBeNull()
        ->and($metadata['server_id'])->toBe(42)
        ->and($metadata['extension'])->toBe('sql')
        ->and($metadata['container_uuid'])->toBe('container-uu');
});

it('rejects malformed terminal filename extensions', function () {
    $filename = '1700000000_1700003600_42_nocontainer_demo_a1b2c3d4.bad-ext';

    expect(TerminalFileHelper::parseFilename($filename))->toBeNull();
});
