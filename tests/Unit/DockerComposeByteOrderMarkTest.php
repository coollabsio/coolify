<?php

use Tests\TestCase;

uses(TestCase::class);

it('parses a Docker Compose file that starts with a UTF-8 byte order mark', function () {
    $compose = "\xEF\xBB\xBFservices:\n  web:\n    image: nginx:alpine\n";

    expect(parseDockerComposeYaml($compose))->toBe([
        'services' => [
            'web' => ['image' => 'nginx:alpine'],
        ],
    ]);

    validateDockerComposeForInjection($compose);
});
