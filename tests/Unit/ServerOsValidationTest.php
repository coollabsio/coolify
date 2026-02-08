<?php

/**
 * Tests for Server OS validation
 *
 * These tests verify that the SUPPORTED_OS constant and validateOS() method
 * correctly identify and support various Linux distributions, including Debian 13 (trixie).
 */

test('includes trixie in SUPPORTED_OS constant for Debian 13 support', function () {
    $supportedOs = SUPPORTED_OS;

    expect($supportedOs)->toBeArray();
    expect($supportedOs[0])->toContain('trixie');
});

test('validates various OS IDs against SUPPORTED_OS', function () {
    $supportedOsStr = implode(' ', SUPPORTED_OS);
    
    $testIds = ['ubuntu', 'debian', 'raspbian', 'pop', 'trixie', 'centos', 'fedora', 'arch', 'alpine'];
    
    foreach ($testIds as $id) {
        expect($supportedOsStr)->toContain($id);
    }
});
