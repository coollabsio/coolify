<?php

use App\Models\Application;
use App\Services\ImageScan\ResourceScans;
use Symfony\Component\Yaml\Yaml;

function composeNamesApp(string $compose): Application
{
    return new Application(['build_pack' => 'dockercompose', 'docker_compose' => $compose]);
}

it('reads the images of a Compose file again after the file changes, also on the same app', function () {
    $application = composeNamesApp(Yaml::dump(['services' => ['web' => ['image' => 'nginx:1.27'], 'db' => ['image' => 'postgres:16']]]));

    expect(ResourceScans::composeServiceNames($application))->toBe(['nginx' => 'web', 'postgres' => 'db'])
        ->and(ResourceScans::composeServiceNames($application))->toBe(['nginx' => 'web', 'postgres' => 'db']);

    $application->docker_compose = Yaml::dump(['services' => ['web' => ['image' => 'caddy:2'], 'cache' => ['image' => '${CACHE_IMAGE:-redis:7}']]]);

    expect(ResourceScans::composeServiceNames($application))->toBe(['caddy' => 'web']);
});

it('reads no images of a Compose file that is not valid YAML, every time', function () {
    $application = composeNamesApp("services:\n  web: [");

    expect(ResourceScans::composeServiceNames($application))->toBe([])
        ->and(ResourceScans::composeServiceNames($application))->toBe([]);
});
