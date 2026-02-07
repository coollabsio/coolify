<?php

/**
 * Tests for Server OS validation
 *
 * These tests verify that the SUPPORTED_OS constant and validateOS() method
 * correctly identify and support various Linux distributions, including Debian 13 (trixie).
 *
 * Background:
 * - Coolify validates server OS by reading /etc/os-release (see Server::validateOS())
 * - The file contains ID (e.g., "debian") and VERSION_CODENAME (e.g., "trixie")
 * - Both values are checked against the SUPPORTED_OS constant
 * - Debian 13's codename is "trixie", which was missing from SUPPORTED_OS
 * - This caused "Unsupported OS type" errors when adding Debian 13 servers
 *
 * Solution:
 * - Added 'trixie' to SUPPORTED_OS constant in bootstrap/helpers/constants.php
 * - All existing Debian installation logic (InstallDocker, InstallPrerequisites) already works
 * - These tests ensure Debian 13 is recognized and no existing distributions are broken
 *
 * Related:
 * - Issue #8154: Debian 13 (Trixie) support
 * - Server::validateOS() in app/Models/Server.php (line 1044)
 * - InstallPrerequisites in app/Actions/Server/InstallPrerequisites.php (line 23)
 * - InstallDocker in app/Actions/Server/InstallDocker.php (line 75)
 */

it('includes trixie in SUPPORTED_OS constant for Debian 13 support', function () {
    $supportedOs = SUPPORTED_OS;

    expect($supportedOs)->toBeArray()
        ->and($supportedOs[0])->toContain('debian')
        ->and($supportedOs[0])->toContain('trixie')
        ->and($supportedOs[0])->toContain('ubuntu')
        ->and($supportedOs[0])->toContain('raspbian')
        ->and($supportedOs[0])->toContain('pop');
})->note('Verifies that Debian 13 (trixie) is explicitly supported in the OS list');

it('includes all major Debian-based distributions', function () {
    $supportedOs = SUPPORTED_OS;
    $debianGroup = $supportedOs[0];

    // All these should be in the first group (Debian-based)
    $expectedDistros = ['ubuntu', 'debian', 'raspbian', 'pop', 'trixie'];

    foreach ($expectedDistros as $distro) {
        expect(str_contains($debianGroup, $distro))->toBeTrue("Expected {$distro} to be in SUPPORTED_OS");
    }
})->note('Ensures all Debian-based distributions are supported');

it('includes all major RHEL-based distributions', function () {
    $supportedOs = SUPPORTED_OS;
    $rhelGroup = $supportedOs[1];

    $expectedDistros = ['centos', 'fedora', 'rhel', 'ol', 'rocky', 'amzn', 'almalinux'];

    foreach ($expectedDistros as $distro) {
        expect(str_contains($rhelGroup, $distro))->toBeTrue("Expected {$distro} to be in SUPPORTED_OS");
    }
})->note('Ensures all RHEL-based distributions are supported');

it('includes SUSE-based distributions', function () {
    $supportedOs = SUPPORTED_OS;
    $suseGroup = $supportedOs[2];

    expect($suseGroup)->toContain('sles')
        ->and($suseGroup)->toContain('opensuse-leap')
        ->and($suseGroup)->toContain('opensuse-tumbleweed');
})->note('Ensures SUSE distributions are supported');

it('includes Arch Linux', function () {
    $supportedOs = SUPPORTED_OS;

    expect($supportedOs)->toContain('arch');
})->note('Ensures Arch Linux is supported');

it('includes Alpine Linux', function () {
    $supportedOs = SUPPORTED_OS;

    expect($supportedOs)->toContain('alpine');
})->note('Ensures Alpine Linux is supported');

it('has validateOS method that checks both ID and ID_LIKE fields', function () {
    // Verify the validateOS method exists and has the correct logic
    $reflection = new ReflectionClass(\App\Models\Server::class);
    $method = $reflection->getMethod('validateOS');
    $source = file_get_contents($reflection->getFileName());

    // Extract the validateOS method
    preg_match('/public function validateOS\(\).*?\n    \}/s', $source, $matches);
    $methodCode = $matches[0] ?? '';

    expect($methodCode)->not->toBeEmpty('validateOS method should exist')
        ->and($methodCode)->toContain('ID')
        ->and($methodCode)->toContain('ID_LIKE')
        ->and($methodCode)->toContain('SUPPORTED_OS')
        ->and($methodCode)->toContain('cat /etc/os-release');
})->note('Verifies that validateOS checks both ID and ID_LIKE from /etc/os-release');

it('validates that InstallPrerequisites uses validateOS', function () {
    $reflection = new ReflectionClass(\App\Actions\Server\InstallPrerequisites::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('validateOS()')
        ->and($source)->toContain('Unsupported OS type for prerequisites installation');
})->note('Ensures InstallPrerequisites action properly validates OS before installation');

it('validates that InstallDocker uses validateOS', function () {
    $reflection = new ReflectionClass(\App\Actions\Server\InstallDocker::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('validateOS()')
        ->and($source)->toContain('Server OS type is not supported');
})->note('Ensures InstallDocker action properly validates OS before installation');

it('validates that InstallPrerequisites handles Debian-based systems', function () {
    $reflection = new ReflectionClass(\App\Actions\Server\InstallPrerequisites::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('contains(\'debian\')')
        ->and($source)->toContain('apt-get update')
        ->and($source)->toContain('apt install');
})->note('Ensures Debian-based systems use apt package manager');

it('validates that InstallDocker handles Debian-based systems', function () {
    $reflection = new ReflectionClass(\App\Actions\Server\InstallDocker::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('contains(\'debian\')')
        ->and($source)->toContain('getDebianDockerInstallCommand');
})->note('Ensures Debian-based systems use correct Docker installation method');
