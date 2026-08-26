<?php

use App\Data\FileEntry;

it('sorts directories before files, then by name case-insensitively', function () {
    $entries = [
        new FileEntry('README.md', 'file', 10, 100),
        new FileEntry('src', 'dir', 0, 100),
        new FileEntry('.env', 'file', 5, 100),
        new FileEntry('assets', 'dir', 0, 100),
    ];

    $names = array_map(fn (FileEntry $e) => $e->name, FileEntry::sort($entries));

    expect($names)->toBe(['assets', 'src', '.env', 'README.md']);
});
