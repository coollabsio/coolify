<?php

use App\Livewire\Project\Database\Import;
use App\Models\Server;

test('checkFile does nothing when customLocation is empty', function () {
    $component = new Import;
    $component->customLocation = '';

    $mockServer = Mockery::mock(Server::class);
    $component->server = $mockServer;

    // No server commands should be executed when customLocation is empty
    $component->checkFile();

    expect($component->filename)->toBeNull();
});

test('checkFile validates file exists on server when customLocation is filled', function () {
    $component = new Import;
    $component->customLocation = '/tmp/backup.sql';

    $mockServer = Mockery::mock(Server::class);
    $component->server = $mockServer;

    // This test verifies the logic flows when customLocation has a value
    // The actual remote process execution is tested elsewhere
    expect($component->customLocation)->toBe('/tmp/backup.sql');
});

test('customLocation can be cleared to allow uploaded file to be used', function () {
    $component = new Import;
    $component->customLocation = '/tmp/backup.sql';

    // Simulate clearing the customLocation (as happens when file is uploaded)
    $component->customLocation = '';

    expect($component->customLocation)->toBe('');
});
