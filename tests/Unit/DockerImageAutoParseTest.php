<?php

use App\Livewire\Project\New\DockerImage;

it('auto-parses complete docker image reference with tag', function () {
    $component = new DockerImage;
    $component->imageName = 'nginx:stable-alpine3.21-perl';
    $component->imageTag = '';
    $component->imageSha256 = '';

    $component->updatedImageName();

    expect($component->imageName)->toBe('nginx')
        ->and($component->imageTag)->toBe('stable-alpine3.21-perl')
        ->and($component->imageSha256)->toBe('');
});

it('auto-parses complete docker image reference with sha256 digest', function () {
    $hash = '4e272eef7ec6a7e76b9c521dcf14a3d397f7c370f48cbdbcfad22f041a1449cb';
    $component = new DockerImage;
    $component->imageName = "nginx@sha256:{$hash}";
    $component->imageTag = '';
    $component->imageSha256 = '';

    $component->updatedImageName();

    expect($component->imageName)->toBe('nginx')
        ->and($component->imageTag)->toBe('')
        ->and($component->imageSha256)->toBe($hash);
});

it('auto-parses complete docker image reference with tag and sha256 digest', function () {
    $hash = '4e272eef7ec6a7e76b9c521dcf14a3d397f7c370f48cbdbcfad22f041a1449cb';
    $component = new DockerImage;
    $component->imageName = "nginx:stable-alpine3.21-perl@sha256:{$hash}";
    $component->imageTag = '';
    $component->imageSha256 = '';

    $component->updatedImageName();

    // When both tag and digest are present, Docker keeps the tag in the name
    // but uses the digest for pulling. The tag becomes part of the image name.
    expect($component->imageName)->toBe('nginx:stable-alpine3.21-perl')
        ->and($component->imageTag)->toBe('')
        ->and($component->imageSha256)->toBe($hash);
});

it('auto-parses registry image with port and tag', function () {
    $component = new DockerImage;
    $component->imageName = 'registry.example.com:5000/myapp:v1.2.3';
    $component->imageTag = '';
    $component->imageSha256 = '';

    $component->updatedImageName();

    expect($component->imageName)->toBe('registry.example.com:5000/myapp')
        ->and($component->imageTag)->toBe('v1.2.3')
        ->and($component->imageSha256)->toBe('');
});

it('auto-parses ghcr image with sha256 digest', function () {
    $hash = 'abc123def456789abcdef123456789abcdef123456789abcdef123456789abc1';
    $component = new DockerImage;
    $component->imageName = "ghcr.io/user/app@sha256:{$hash}";
    $component->imageTag = '';
    $component->imageSha256 = '';

    $component->updatedImageName();

    expect($component->imageName)->toBe('ghcr.io/user/app')
        ->and($component->imageTag)->toBe('')
        ->and($component->imageSha256)->toBe($hash);
});

it('does not auto-parse if user has manually filled tag field', function () {
    $component = new DockerImage;
    $component->imageTag = 'latest'; // User manually set this FIRST
    $component->imageSha256 = '';
    $component->imageName = 'nginx:stable'; // Then user enters image name

    $component->updatedImageName();

    // Should not auto-parse because tag is already set
    expect($component->imageName)->toBe('nginx:stable')
        ->and($component->imageTag)->toBe('latest')
        ->and($component->imageSha256)->toBe('');
});

it('does not auto-parse if user has manually filled sha256 field', function () {
    $hash = '4e272eef7ec6a7e76b9c521dcf14a3d397f7c370f48cbdbcfad22f041a1449cb';
    $component = new DockerImage;
    $component->imageSha256 = $hash; // User manually set this FIRST
    $component->imageTag = '';
    $component->imageName = 'nginx:stable'; // Then user enters image name

    $component->updatedImageName();

    // Should not auto-parse because sha256 is already set
    expect($component->imageName)->toBe('nginx:stable')
        ->and($component->imageTag)->toBe('')
        ->and($component->imageSha256)->toBe($hash);
});

it('does not auto-parse plain image name without tag or digest', function () {
    $component = new DockerImage;
    $component->imageName = 'nginx';
    $component->imageTag = '';
    $component->imageSha256 = '';

    $component->updatedImageName();

    // Should leave as-is since there's nothing to parse
    expect($component->imageName)->toBe('nginx')
        ->and($component->imageTag)->toBe('')
        ->and($component->imageSha256)->toBe('');
});

it('handles parsing errors gracefully', function () {
    $component = new DockerImage;
    $component->imageName = 'registry.io:5000/myapp:v1.2.3';
    $component->imageTag = '';
    $component->imageSha256 = '';

    // Should not throw exception
    expect(fn () => $component->updatedImageName())->not->toThrow(\Exception::class);

    // Should successfully parse this valid image
    expect($component->imageName)->toBe('registry.io:5000/myapp')
        ->and($component->imageTag)->toBe('v1.2.3');
});
