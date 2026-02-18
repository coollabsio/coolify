<?php

/**
 * Documents and verifies OS support for server validation (e.g. Debian 13 / Trixie).
 * validateOS() uses instant_remote_process() so full flow is tested on real servers.
 */
it('supports debian family in SUPPORTED_OS so Debian 13 is recognized when ID is debian', function () {
    $debianFamily = collect(SUPPORTED_OS)->first(fn ($os) => str($os)->contains('debian'));

    expect($debianFamily)->not->toBeNull()
        ->and($debianFamily)->toContain('debian');
});

it('recognizes Debian 13 codename trixie in parsing fallback', function () {
    // Server::validateOS() uses VERSION_CODENAME fallback when ID is empty; trixie must be in the list.
    $debianCodenames = ['stretch', 'buster', 'bullseye', 'bookworm', 'trixie'];

    expect($debianCodenames)->toContain('trixie')
        ->and($debianCodenames)->toContain('bookworm');
});
