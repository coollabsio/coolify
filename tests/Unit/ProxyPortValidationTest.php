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

it('accepts Docker Compose variables and checks only ports with a resolvable default', function (mixed $ports, array $expected) {
    $configuration = Yaml::dump(['services' => ['traefik' => ['ports' => $ports]]], 8, 2);

    expect(ProxyPortParser::fromConfiguration($configuration))->toBe($expected);
})->with([
    'host port default' => [['${HTTP_PORT:-80}:80'], [80]],
    'host port default without colon' => [['${HTTP_PORT-80}:80'], [80]],
    'host port without default' => [['${HTTP_PORT}:80'], []],
    'unbraced host port' => [['$HTTP_PORT:80'], []],
    'required host port' => [['${HTTP_PORT:?HTTP_PORT must be set}:80'], []],
    'alternative host port' => [['${HTTP_PORT:+8080}:80'], []],
    'host IP with a host port default' => [['127.0.0.1:${P:-8080}:8080'], [8080]],
    'host IP default' => [['${BIND_IP:-0.0.0.0}:80:80'], [80]],
    'host IP without default' => [['${BIND_IP}:443:443'], []],
    'container port variable' => [['8080:${CONTAINER_PORT}'], []],
    'default with protocol' => [['${HTTPS_PORT:-443}:443/udp'], [443]],
    'protocol variable' => [['443:443/${PROTOCOL:-udp}'], [443]],
    'invalid default is skipped like an unset variable' => [['${HTTP_PORT:-abc}:80'], []],
    'default range' => [['${RANGE:-10000-10100}:10000-10100'], []],
    'mixed with literal ports' => [['${HTTP_PORT:-80}:80', '${HTTPS_PORT}:443', '8080:8080'], [80, 8080]],
    'long syntax published default' => [[['target' => 80, 'published' => '${HTTP_PORT:-8080}']], [8080]],
    'long syntax published variable' => [[['target' => 80, 'published' => '${HTTP_PORT}']], []],
    'long syntax host IP default' => [[['target' => 80, 'published' => 80, 'host_ip' => '${BIND_IP:-127.0.0.1}']], [80]],
    'long syntax target variable' => [[['target' => '${TARGET}', 'published' => 9000]], []],
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
    'environment variable followed by a semicolon' => '${HTTP_PORT:-80};id:80',
    'environment variable with command substitution' => '${HTTP_PORT}$(id):80',
    'environment variable with backticks' => '`id`${HTTP_PORT}:80',
    'environment variable default with command substitution' => '${HTTP_PORT:-$(id)}:80',
    'environment variable with a pipe' => '${HTTP_PORT}:80|id',
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
    'variable with command substitution' => [['target' => 80, 'published' => '${HTTP_PORT}$(id)']],
    'variable with an invalid protocol' => [['target' => 80, 'published' => '${HTTP_PORT}', 'protocol' => 'http']],
]);

it('rejects invalid proxy port collection shapes', function (mixed $ports) {
    $configuration = Yaml::dump(['services' => ['traefik' => ['ports' => $ports]]], 8, 2);

    expect(fn () => ProxyPortParser::fromConfiguration($configuration))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'scalar' => ['80:80'],
    'map' => [['published' => 80]],
    'boolean' => [true],
]);

it('accepts an empty ports key like v4.3.23 did', function (string $configuration) {
    expect(ProxyPortParser::fromConfiguration($configuration))->toBe([])
        ->and(ProxyPortParser::publishesOnlyUncheckedPorts($configuration))->toBeFalse();
})->with([
    'empty value' => ["services:\n  traefik:\n    image: traefik:v3.6\n    ports:\n"],
    'explicit null' => ["services:\n  caddy:\n    ports: null\n"],
]);

it('reports proxies whose published ports cannot be checked for conflicts', function (string $configuration, bool $expected) {
    expect(ProxyPortParser::publishesOnlyUncheckedPorts($configuration))->toBe($expected);
})->with([
    'variables without defaults' => ["services:\n  traefik:\n    ports: ['\${HTTP_PORT}:80', '\${HTTPS_PORT}:443']\n", true],
    'a concrete port' => ["services:\n  traefik:\n    ports: ['\${HTTP_PORT}:80', '443:443']\n", false],
    'only port ranges' => ["services:\n  traefik:\n    ports: ['10000-10100:10000-10100']\n", true],
    'a variable with a default' => ["services:\n  traefik:\n    ports: ['\${HTTP_PORT:-80}:80']\n", false],
    'no ports' => ["services:\n  traefik:\n    image: traefik:v3.6\n", false],
    'empty ports' => ["services:\n  traefik:\n    ports: []\n", false],
]);

it('accepts Docker Compose merge tags in proxy configurations', function (string $configuration, array $expected) {
    expect(ProxyPortParser::fromConfiguration($configuration))->toBe($expected);
})->with([
    'override ports' => ["services:\n  traefik:\n    ports: !override\n      - '80:80'\n      - '443:443'\n", [80, 443]],
    'reset ports' => ["services:\n  traefik:\n    ports: !reset []\n", []],
    'reset labels next to ports' => ["services:\n  caddy:\n    labels: !reset []\n    ports: ['8080:80']\n", [8080]],
    'override a long syntax port' => ["services:\n  traefik:\n    ports: !override\n      - target: 80\n        published: !!str 8080\n", [8080]],
]);

it('still rejects malformed ports inside Docker Compose merge tags', function () {
    expect(fn () => ProxyPortParser::fromConfiguration("services:\n  traefik:\n    ports: !override\n      - '\$(id):80'\n"))
        ->toThrow(InvalidArgumentException::class);
});
