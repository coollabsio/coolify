<?php

use Symfony\Component\Yaml\Yaml;

it('selects only TCP container ports for HTTP routing and confirmation', function (array $service, array $expected) {
    $compose = Yaml::dump(['services' => ['web' => $service]]);

    expect(firstDockerComposeServicePort($service))->toBe($expected[0] ?? null)
        ->and(dockerComposeServicePort($compose, 'web'))->toBe($expected[0] ?? null)
        ->and(dockerComposeServicePorts($compose, 'web'))->toBe($expected);
})->with([
    'expose UDP first' => [['expose' => ['53/udp', '8080/tcp']], [8080]],
    'published UDP first' => [['ports' => ['53:53/udp', '18080:8080/tcp']], [8080]],
    'long syntax UDP first' => [['ports' => [['target' => 53, 'protocol' => 'udp'], ['target' => 8080, 'published' => 18080, 'protocol' => 'tcp']]], [8080]],
    'implicit TCP' => [['expose' => [80], 'ports' => [['target' => 8080], '18081:8081']], [80, 8080, 8081]],
    'UDP only' => [['expose' => ['53/udp'], 'ports' => [['target' => 67, 'protocol' => 'udp']]], []],
    'other protocol' => [['ports' => ['9000:9000/sctp', '8080']], [8080]],
    'duplicates' => [['expose' => ['8080/udp', '8080/tcp'], 'ports' => ['18080:8080']], [8080]],
]);
