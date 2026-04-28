<?php

test('generate_application_name strips owner from git repository', function () {
    $name = generate_application_name('coollabsio/coolify', 'main', 'test123');

    expect($name)->toBe('coolify:main-test123');
    expect($name)->not->toContain('coollabsio');
});

test('generate_application_name handles repository without owner', function () {
    $name = generate_application_name('coolify', 'main', 'test123');

    expect($name)->toBe('coolify:main-test123');
});

test('generate_application_name handles deeply nested repository path', function () {
    $name = generate_application_name('org/sub/repo-name', 'develop', 'abc456');

    expect($name)->toBe('repo-name:develop-abc456');
    expect($name)->not->toContain('org');
    expect($name)->not->toContain('sub');
});
