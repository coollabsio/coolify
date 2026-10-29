<?php

use App\Services\ImageScan\ImageRef;

$imageRefDigest = 'sha256:'.str_repeat('ab', 32);

it('parses a Docker Hub official image', function () {
    $ref = ImageRef::parse('nginx');

    expect($ref->registry)->toBe('docker.io')
        ->and($ref->repository)->toBe('library/nginx')
        ->and($ref->tag)->toBeNull()
        ->and($ref->digest)->toBeNull()
        ->and($ref->isDockerOfficial())->toBeTrue()
        ->and($ref->qualifiedName())->toBe('docker.io/library/nginx')
        ->and((string) $ref)->toBe('nginx');
});

it('treats namespaced and other registry images as not official', function (string $input) {
    expect(ImageRef::parse($input)->isDockerOfficial())->toBeFalse();
})->with(['grafana/grafana', 'grafana/grafana:10.4', 'ghcr.io/x/y', 'quay.io/library/nginx', 'localhost:5000/nginx']);

it('normalizes explicit Docker Hub names', function (string $input) {
    $ref = ImageRef::parse($input);

    expect($ref->registry)->toBe('docker.io')
        ->and($ref->repository)->toBe('library/postgres')
        ->and($ref->tag)->toBe('15.0-alpine')
        ->and($ref->isDockerOfficial())->toBeTrue()
        ->and((string) $ref)->toBe('postgres:15.0-alpine');
})->with(['postgres:15.0-alpine', 'docker.io/postgres:15.0-alpine', 'docker.io/library/postgres:15.0-alpine', 'index.docker.io/library/postgres:15.0-alpine']);

it('splits a registry with a port from the tag', function () {
    $ref = ImageRef::parse('localhost:5000/team/app:1.2');

    expect($ref->registry)->toBe('localhost:5000')
        ->and($ref->repository)->toBe('team/app')
        ->and($ref->tag)->toBe('1.2')
        ->and((string) $ref)->toBe('localhost:5000/team/app:1.2');

    expect(ImageRef::parse('localhost:5000/app')->tag)->toBeNull()
        ->and(ImageRef::parse('localhost:5000/app')->repository)->toBe('app');
});

it('reads the first path component as a registry only when it looks like a host', function () {
    expect(ImageRef::parse('localhost/app:1')->registry)->toBe('localhost')
        ->and(ImageRef::parse('localhost/app:1')->repository)->toBe('app')
        ->and(ImageRef::parse('team/app:1')->registry)->toBe('docker.io')
        ->and(ImageRef::parse('team/app:1')->repository)->toBe('team/app')
        ->and(ImageRef::parse('my.registry/team/app')->registry)->toBe('my.registry');
});

it('parses a digest reference without a tag', function () use ($imageRefDigest) {
    $ref = ImageRef::parse("ghcr.io/a/b@{$imageRefDigest}");

    expect($ref->registry)->toBe('ghcr.io')
        ->and($ref->repository)->toBe('a/b')
        ->and($ref->tag)->toBeNull()
        ->and($ref->digest)->toBe($imageRefDigest)
        ->and((string) $ref)->toBe("ghcr.io/a/b@{$imageRefDigest}");
});

it('refuses references that are not valid image names', function (string $input) {
    ImageRef::parse($input);
})->with([
    'shell injection' => 'nginx;rm -rf /',
    'command substitution' => 'nginx:$(id)',
    'space' => 'nginx latest',
    'newline' => "nginx\nid",
    'quote' => "nginx'",
    'empty tag' => 'nginx:',
    'digest without hash' => 'nginx@sha256',
    'uppercase digest' => 'nginx@sha256:'.str_repeat('AB', 32),
    'tag and digest' => 'nginx:1@sha256:'.str_repeat('ab', 32),
    'empty' => '',
])->throws(InvalidArgumentException::class);

it('replaces the tag and drops the digest', function () use ($imageRefDigest) {
    $ref = ImageRef::parse("postgres@{$imageRefDigest}")->withTag('15-alpine');

    expect($ref->tag)->toBe('15-alpine')
        ->and($ref->digest)->toBeNull()
        ->and((string) $ref)->toBe('postgres:15-alpine');

    ImageRef::parse('postgres')->withTag('bad tag');
})->throws(InvalidArgumentException::class);

it('validates tags and digests', function () use ($imageRefDigest) {
    expect(ImageRef::isValidTag('15.0-alpine'))->toBeTrue()
        ->and(ImageRef::isValidTag('_x'))->toBeTrue()
        ->and(ImageRef::isValidTag(str_repeat('a', 128)))->toBeTrue()
        ->and(ImageRef::isValidTag(str_repeat('a', 129)))->toBeFalse()
        ->and(ImageRef::isValidTag('.hidden'))->toBeFalse()
        ->and(ImageRef::isValidTag('-x'))->toBeFalse()
        ->and(ImageRef::isValidTag("1\n"))->toBeFalse()
        ->and(ImageRef::isValidDigest($imageRefDigest))->toBeTrue()
        ->and(ImageRef::isValidDigest(strtoupper($imageRefDigest)))->toBeFalse()
        ->and(ImageRef::isValidDigest('sha256:abc'))->toBeFalse()
        ->and(ImageRef::isValidDigest($imageRefDigest."\n"))->toBeFalse();
});
