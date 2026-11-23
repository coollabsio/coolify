<?php

use App\Support\ContainerLogArchive;

it('lists archives of removed containers newest first', function () {
    $output = implode("\n", [
        '1790000000.5 app-old.aaaaaaaaaaaa.log',
        '1790000900.1 app-new.bbbbbbbbbbbb.log',
        '1790000500.0 app-running.cccccccccccc.log',
        '',
    ]);

    $entries = ContainerLogArchive::parseList($output, 'server-1', ['cccccccccccc0123456789']);

    expect($entries)->toBe([
        ['server' => 'server-1', 'file' => 'app-new.bbbbbbbbbbbb.log', 'container' => 'app-new', 'id' => 'bbbbbbbbbbbb', 'modifiedAt' => 1790000900],
        ['server' => 'server-1', 'file' => 'app-old.aaaaaaaaaaaa.log', 'container' => 'app-old', 'id' => 'aaaaaaaaaaaa', 'modifiedAt' => 1790000000],
    ]);
});

it('ignores lines that are not archive files', function () {
    $output = implode("\n", [
        'garbage',
        '1790000000 ../evil.aaaaaaaaaaaa.log',
        '1790000000 app.aaaaaaaaaaaa.log.1',
        '1790000000 app.not-an-id.log',
        'x app.aaaaaaaaaaaa.log',
    ]);

    expect(ContainerLogArchive::parseList($output, 'server-1', []))->toBe([]);
});

it('reads the rotated file first and tails the newest lines', function () {
    expect(ContainerLogArchive::readCommand('owner1', 'app.aaaaaaaaaaaa.log', 100, true))
        ->toBe("cat '/data/coolify/sentinel/container-logs/owner1/app.aaaaaaaaaaaa.log.1' '/data/coolify/sentinel/container-logs/owner1/app.aaaaaaaaaaaa.log' 2>/dev/null | tail -n 100");
});

it('reads all lines without timestamps', function () {
    expect(ContainerLogArchive::readCommand('owner1', 'app.aaaaaaaaaaaa.log', null, false))
        ->toBe("cat '/data/coolify/sentinel/container-logs/owner1/app.aaaaaaaaaaaa.log.1' '/data/coolify/sentinel/container-logs/owner1/app.aaaaaaaaaaaa.log' 2>/dev/null | cut -d ' ' -f 2-");
});

it('rejects paths outside the archive', function (string $owner, string $file) {
    expect(ContainerLogArchive::readCommand($owner, $file, 100, true))->toBeNull();
})->with([
    'file traversal' => ['owner1', '../../etc/passwd.aaaaaaaaaaaa.log'],
    'file with slash' => ['owner1', 'a/b.aaaaaaaaaaaa.log'],
    'owner traversal' => ['..', 'app.aaaaaaaaaaaa.log'],
    'owner with quote' => ["o'1", 'app.aaaaaaaaaaaa.log'],
]);
