<?php

use App\Domain\Deployment\DeploymentResult;

it('is able to create an instance of deployment result', function () {
    $result = new DeploymentResult();

    expect($result)->toBeInstanceOf(DeploymentResult::class);
});

it('is able to fetch null string of docker compose base64 when it is not set', function () {
    $result = new DeploymentResult();

    expect($result->getDockerComposeBase64())->toBeNull();
});

it('is able to fetch null string of docker compose base64 when it is set', function () {
    $result = new DeploymentResult();
    $result->setDockerComposeBase64('base64');

    expect($result->getDockerComposeBase64())->toBe('base64');
});

it('is able to fetch the docker compose location if it is not set', function () {

    $result = new DeploymentResult();

    expect($result->getDockerComposeLocation())->toBe('/docker-compose.yml');
});

it('is able to fetch the docker compose location if it is set', function () {

    $result = new DeploymentResult();
    $result->setDockerComposeLocation('/docker-compose-test.yml');

    expect($result->getDockerComposeLocation())->toBe('/docker-compose-test.yml');
});

it('is able to get nixpacks plan when it is not set', function () {
    $result = new DeploymentResult();

    expect($result->getNixpacksPlanJson())->toBeNull();
});

it('is able to fetch nixpacks plan when it is set', function () {

    $result = new DeploymentResult();
    $result->setNixpacksPlanJson('nixpacks plan');

    expect($result->getNixpacksPlanJson())->toBe('nixpacks plan');
});

it('is able to check if new version is healthy when it is not set', function () {
    $result = new DeploymentResult();

    expect($result->isNewVersionHealth())->toBeNull();
});

it('is able to check if new version is healthy when it is set', function () {

    $result = new DeploymentResult();
    $result->setNewVersionHealthy(true);

    expect($result->isNewVersionHealth())->toBeTrue();
});

it('is able to check if new version is healthy when it is set to false', function () {

    $result = new DeploymentResult();
    $result->setNewVersionHealthy(false);

    expect($result->isNewVersionHealth())->toBeFalse();
});

it('is able to fetch the default dockerfile location name', function () {

    $result = new DeploymentResult();

    expect($result->getDockerFileLocation())->toBe('/Dockerfile');
});

it('is able to set the dockerfile location name', function () {

    $result = new DeploymentResult();
    $result->setDockerFileLocation('/Dockerfile-test');

    expect($result->getDockerFileLocation())->toBe('/Dockerfile-test');
});

it('is able to fetch the docker compose custom start command per default', function () {
    $result = new DeploymentResult();

    expect($result->getDockerComposeCustomStartCommand())->toBeNull();
});

it('is able to set the docker compose custom start command', function () {
    $result = new DeploymentResult();
    $result->setDockerComposeCustomStartCommand('docker-compose up -d');

    expect($result->getDockerComposeCustomStartCommand())->toBe('docker-compose up -d');
});

it('is able to fetch the docker compose custom build command per default', function () {
    $result = new DeploymentResult();

    expect($result->getDockerComposeCustomBuildCommand())->toBeNull();
});

it('is able to fetch the docker compose custom build command', function () {

    $result = new DeploymentResult();
    $result->setDockerComposeCustomBuildCommand('docker-compose build');

    expect($result->getDockerComposeCustomBuildCommand())->toBe('docker-compose build');
});
