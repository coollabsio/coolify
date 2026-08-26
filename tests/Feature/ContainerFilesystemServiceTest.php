<?php

use App\Data\FileEntry;
use App\Models\Server;
use App\Services\ContainerFilesystemService;

function fsService(): ContainerFilesystemService
{
    $server = Server::factory()->make(['id' => 999]);

    return new ContainerFilesystemService($server, 'app-123');
}

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

it('builds a listing command with an escaped path and the container name', function () {
    $cmd = fsService()->buildListCommand('/var/www/html');

    expect($cmd)
        ->toContain('docker exec')
        ->toContain('app-123')
        ->toContain(escapeshellarg('/var/www/html'));
});

it('rejects an unsafe listing path', function () {
    fsService()->buildListCommand('/tmp/$(reboot)');
})->throws(Exception::class);

it('parses a tab-delimited listing into sorted FileEntry rows', function () {
    $raw = implode("\n", [
        "file\t10\t1700000000\tREADME.md",
        "dir\t0\t1700000001\tsrc",
        "file\t5\t1700000002\t.env",
    ]);

    $entries = fsService()->parseListing($raw);

    expect($entries)->toHaveCount(3);
    expect($entries[0]->name)->toBe('src');
    expect($entries[0]->type)->toBe('dir');
    expect($entries[1]->name)->toBe('.env');
    expect($entries[2]->name)->toBe('README.md');
});

it('parses an empty listing to an empty array', function () {
    expect(fsService()->parseListing(null))->toBe([]);
    expect(fsService()->parseListing(''))->toBe([]);
});
