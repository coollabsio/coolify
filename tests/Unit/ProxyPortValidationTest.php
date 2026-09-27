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
    'port ranges are accepted but not checked one by one' => ["services:\n  traefik:\n    ports: ['80:80', '10000-10100:10000-10100/udp', '127.0.0.1:5000-5010:5000-5010', '3000-3005', '8000-8010:80']\n", [80]],
    'protocols in any letter case' => ["services:\n  traefik:\n    ports: ['53:53/UDP', '9000:9000/TCP', '5432:5432/sctp']\n", [53, 9000, 5432]],
    'random host port' => ["services:\n  traefik:\n    ports: ['127.0.0.1::5000', '[::1]::6000']\n", []],
    'IPv6 host with a range' => ["services:\n  traefik:\n    ports: ['[::1]:6000-6001:6000-6001']\n", []],
    'long syntax with a published range' => ["services:\n  caddy:\n    ports:\n      - target: 443\n        published: '8443-8444'\n        protocol: UDP\n", []],
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
    'empty protocol' => '80:80/',
    'host injection' => '`id`:8080:80',
    'reversed range' => '10-5:10-5',
    'range above the limit' => '65535-65536:1-2',
    'range with command substitution' => '80-$(id):80',
    'open range' => '80-:80',
    'empty host port without a host IP' => ':80',
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
    'reversed published range' => [['target' => 80, 'published' => '90-80']],
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
