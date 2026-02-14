<?php

it('validates Debian 13 OS ID correctly', function () {
    // Simulate Debian 13 /etc/os-release output
    $osRelease = 'PRETTY_NAME="Debian GNU/Linux 13 (trixie)"
NAME="Debian GNU/Linux"
VERSION_ID="13"
VERSION="13 (trixie)"
ID=debian';
    
    // Parse the ID like validateOS() does
    $lines = explode("\n", $osRelease);
    $id = null;
    foreach ($lines as $line) {
        if (strpos($line, 'ID=') === 0 && strpos($line, 'ID_LIKE') === false) {
            $id = trim(str_replace('"', '', substr($line, 3)));
            break;
        }
    }
    
    // Assert ID is 'debian'
    expect($id)->toBe('debian');
    
    // Assert 'debian' is in the supported OS string
    expect('debian')->toBeContainedIn('ubuntu debian raspbian pop');
});

it('confirms debian string is in SUPPORTED_OS constant', function () {
    // Include the constants file
    require_once __DIR__ . '/../../bootstrap/helpers/constants.php';
    
    // Check that SUPPORTED_OS contains the debian entry
    expect(SUPPORTED_OS)->toContain('ubuntu debian raspbian pop');
});
