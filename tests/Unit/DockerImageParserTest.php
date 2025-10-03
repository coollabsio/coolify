<?php

use App\Services\DockerImageParser;

it('parses regular image with tag', function () {
    $parser = new DockerImageParser;
    $parser->parse('nginx:latest');

    expect($parser->getImageName())->toBe('nginx')
        ->and($parser->getTag())->toBe('latest')
        ->and($parser->isImageHash())->toBeFalse()
        ->and($parser->toString())->toBe('nginx:latest');
});

it('parses image with sha256 hash using colon format', function () {
    $parser = new DockerImageParser;
    $hash = '59e02939b1bf39f16c93138a28727aec520bb916da021180ae502c61626b3cf0';
    $parser->parse("ghcr.io/benjaminehowe/rail-disruptions:{$hash}");

    expect($parser->getFullImageNameWithoutTag())->toBe('ghcr.io/benjaminehowe/rail-disruptions')
        ->and($parser->getTag())->toBe($hash)
        ->and($parser->isImageHash())->toBeTrue()
        ->and($parser->toString())->toBe("ghcr.io/benjaminehowe/rail-disruptions@sha256:{$hash}")
        ->and($parser->getFullImageNameWithHash())->toBe("ghcr.io/benjaminehowe/rail-disruptions@sha256:{$hash}");
});

it('parses image with sha256 hash using at sign format', function () {
    $parser = new DockerImageParser;
    $hash = '59e02939b1bf39f16c93138a28727aec520bb916da021180ae502c61626b3cf0';
    $parser->parse("nginx@sha256:{$hash}");

    expect($parser->getImageName())->toBe('nginx')
        ->and($parser->getTag())->toBe($hash)
        ->and($parser->isImageHash())->toBeTrue()
        ->and($parser->toString())->toBe("nginx@sha256:{$hash}")
        ->and($parser->getFullImageNameWithHash())->toBe("nginx@sha256:{$hash}");
});

it('parses registry image with hash', function () {
    $parser = new DockerImageParser;
    $hash = 'abc123def456789abcdef123456789abcdef123456789abcdef123456789abc1';
    $parser->parse("docker.io/library/nginx:{$hash}");

    expect($parser->getFullImageNameWithoutTag())->toBe('docker.io/library/nginx')
        ->and($parser->getTag())->toBe($hash)
        ->and($parser->isImageHash())->toBeTrue()
        ->and($parser->toString())->toBe("docker.io/library/nginx@sha256:{$hash}");
});

it('parses image without tag defaults to latest', function () {
    $parser = new DockerImageParser;
    $parser->parse('nginx');

    expect($parser->getImageName())->toBe('nginx')
        ->and($parser->getTag())->toBe('latest')
        ->and($parser->isImageHash())->toBeFalse()
        ->and($parser->toString())->toBe('nginx:latest');
});

it('parses registry with port', function () {
    $parser = new DockerImageParser;
    $parser->parse('registry.example.com:5000/myapp:latest');

    expect($parser->getFullImageNameWithoutTag())->toBe('registry.example.com:5000/myapp')
        ->and($parser->getTag())->toBe('latest')
        ->and($parser->isImageHash())->toBeFalse();
});

it('parses registry with port and hash', function () {
    $parser = new DockerImageParser;
    $hash = '1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef';
    $parser->parse("registry.example.com:5000/myapp:{$hash}");

    expect($parser->getFullImageNameWithoutTag())->toBe('registry.example.com:5000/myapp')
        ->and($parser->getTag())->toBe($hash)
        ->and($parser->isImageHash())->toBeTrue()
        ->and($parser->toString())->toBe("registry.example.com:5000/myapp@sha256:{$hash}");
});

it('identifies valid sha256 hashes', function () {
    $validHashes = [
        '59e02939b1bf39f16c93138a28727aec520bb916da021180ae502c61626b3cf0',
        '1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef',
        'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890',
    ];

    foreach ($validHashes as $hash) {
        $parser = new DockerImageParser;
        $parser->parse("image:{$hash}");
        expect($parser->isImageHash())->toBeTrue("Hash {$hash} should be recognized as valid SHA256");
    }
});

it('identifies invalid sha256 hashes', function () {
    $invalidHashes = [
        'latest',
        'v1.2.3',
        'abc123', // too short
        '59e02939b1bf39f16c93138a28727aec520bb916da021180ae502c61626b3cf', // too short
        '59e02939b1bf39f16c93138a28727aec520bb916da021180ae502c61626b3cf00', // too long
        '59e02939b1bf39f16c93138a28727aec520bb916da021180ae502c61626b3cfg0', // invalid char
    ];

    foreach ($invalidHashes as $hash) {
        $parser = new DockerImageParser;
        $parser->parse("image:{$hash}");
        expect($parser->isImageHash())->toBeFalse("Hash {$hash} should not be recognized as valid SHA256");
    }
});
