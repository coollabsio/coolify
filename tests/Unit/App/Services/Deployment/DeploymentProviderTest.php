<?php

use App\Models\Server;
use App\Services\Deployment\DeploymentHelper;
use App\Services\Deployment\DeploymentProvider;
use App\Services\Remote\InstantRemoteProcessFactory;
use App\Services\Remote\Provider\RemoteProcessProvider;
use App\Services\Remote\RemoteProcessExecutionerManager;
use App\Services\Remote\RemoteProcessManager;

it('is able to create an instace of a deployment provider', function () {
    $mockedRemoteProcessProvider = Mockery::mock(RemoteProcessProvider::class);
    $mockedInstantRemoteProcessFactory = Mockery::mock(InstantRemoteProcessFactory::class);
    $mockedRemoteProcessExecutionerManager = Mockery::mock(RemoteProcessExecutionerManager::class);

    $deploymentProvider = new DeploymentProvider($mockedRemoteProcessProvider, $mockedInstantRemoteProcessFactory, $mockedRemoteProcessExecutionerManager);

    expect($deploymentProvider)->toBeInstanceOf(DeploymentProvider::class);
});

it('is able to create an instance of a deployment helper', function () {
    $mockedRemoteProcessProvider = Mockery::mock(RemoteProcessProvider::class);
    $mockedInstantRemoteProcessFactory = Mockery::mock(InstantRemoteProcessFactory::class);
    $mockedRemoteProcessExecutionerManager = Mockery::mock(RemoteProcessExecutionerManager::class);

    $mockedRemoteProcessProvider->shouldReceive('forServer')->andReturn(Mockery::mock(RemoteProcessManager::class))
        ->times(1);

    $deploymentProvider = new DeploymentProvider($mockedRemoteProcessProvider, $mockedInstantRemoteProcessFactory, $mockedRemoteProcessExecutionerManager);

    $mockedServer = Mockery::mock(Server::class);

    $deploymentHelper = $deploymentProvider->forServer($mockedServer);

    expect($deploymentHelper)->toBeInstanceOf(DeploymentHelper::class);
});
