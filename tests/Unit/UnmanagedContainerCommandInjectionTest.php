<?php

use App\Support\ValidationPatterns;

it('rejects container IDs with command injection characters', function (string $id) {
    expect(ValidationPatterns::isValidContainerName($id))->toBeFalse();
})->with([
    'semicolon injection' => 'x; id > /tmp/pwned',
    'pipe injection' => 'x | cat /etc/passwd',
    'command substitution backtick' => 'x`whoami`',
    'command substitution dollar' => 'x$(whoami)',
    'ampersand background' => 'x & rm -rf /',
    'double ampersand' => 'x && curl attacker.com',
    'newline injection' => "x\nid",
    'space injection' => 'x id',
    'redirect output' => 'x > /tmp/pwned',
    'redirect input' => 'x < /etc/passwd',
]);

it('accepts valid Docker container IDs', function (string $id) {
    expect(ValidationPatterns::isValidContainerName($id))->toBeTrue();
})->with([
    'short hex id' => 'abc123def456',
    'full sha256 id' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
    'container name' => 'my-container',
    'name with dots' => 'my.container.name',
    'name with underscores' => 'my_container_name',
]);
