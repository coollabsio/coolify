<?php

use App\Services\ProxyPortParser;
use Symfony\Component\Yaml\Yaml;

it('extracts normalized published proxy ports from valid compose syntax', function (string $configuration, array $expected) {
    expect(ProxyPortParser::fromConfiguration($configuration))->toBe($expected);
})->with([
    'traefik short syntax' => ["services:\n  traefik:\n    ports: [80, '443:443', '127.0.0.1:8080:80', '0443:443/udp']\n", [80, 443, 8080]],
    'caddy long syntax' => ["services:\n  caddy:\n    ports:\n      - target: 80\n        published: '8080'\n        protocol: tcp\n      - target: 443\n", [8080, 443]],
    'both supported proxies' => ["services:\n  traefik:\n    ports: ['80:80']\n  caddy:\n    ports: ['443:443/udp']\n", [80, 443]],
    'range boundaries' => ["services:\n  traefik:\n    ports: ['1:1', '65535:65535']\n", [1, 65535]],
]);

it('rejects malformed proxy port values', function (mixed $port) {
    $configuration = Yaml::dump(['services' => ['traefik' => ['ports' => [$port]]]], 8, 2);

    expect(fn () => ProxyPortParser::fromConfiguration($configuration))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'zero' => 0,
    'too large' => 65536,
    'negative' => -1,
    'leading plus' => '+80',
    'decimal' => 80.5,
    'scientific notation' => '8e1',
    'comma separated' => '80,443',
    'environment variable' => '${HTTP_PORT:-80}:80',
    'leading whitespace' => ' 80',
    'trailing whitespace' => '80 ',
    'newline' => "80\nid",
    'semicolon' => '80;id',
    'command substitution' => '$(id):80',
    'backticks' => '`id`:80',
    'pipe' => '80|id',
    'logical operator' => '80&&id',
    'quote' => "80'",
    'option prefix' => '--help',
    'null' => null,
    'boolean' => true,
    'nested sequence' => [['80']],
    'nested map' => [['target' => ['80']]],
    'invalid protocol' => '80:80/http',
    'host injection' => '`id`:8080:80',
]);

it('rejects malformed proxy ports in long compose syntax', function (array $port) {
    $configuration = Yaml::dump(['services' => ['caddy' => ['ports' => [$port]]]], 8, 2);

    expect(fn () => ProxyPortParser::fromConfiguration($configuration))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'malicious published port' => [['target' => 80, 'published' => '$(id)']],
    'malicious target port' => [['target' => '80;id', 'published' => 8080]],
    'invalid published type' => [['target' => 80, 'published' => true]],
    'missing target' => [['published' => 8080]],
    'invalid protocol' => [['target' => 80, 'published' => 8080, 'protocol' => 'http']],
    'host injection' => [['target' => 80, 'published' => 8080, 'host_ip' => '$(id)']],
]);

it('rejects invalid proxy port collection shapes', function (mixed $ports) {
    $configuration = Yaml::dump(['services' => ['traefik' => ['ports' => $ports]]], 8, 2);

    expect(fn () => ProxyPortParser::fromConfiguration($configuration))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'scalar' => ['80:80'],
    'map' => [['published' => 80]],
    'null' => [null],
    'boolean' => [true],
]);
