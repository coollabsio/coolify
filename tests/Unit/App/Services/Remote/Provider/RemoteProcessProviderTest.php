<?php

use App\Models\Server;
use App\Services\Remote\InstantRemoteProcessFactory;
use App\Services\Remote\Provider\RemoteProcessProvider;
use App\Services\Remote\RemoteProcessExecutionerManager;
use App\Services\Remote\RemoteProcessManager;

it('is able to create an instance of remote process provider', function () {
    $instantRemoteProcessFactory = Mockery::mock(InstantRemoteProcessFactory::class);
    $executionerManager = Mockery::mock(RemoteProcessExecutionerManager::class);

    $remoteProcessProvider = new RemoteProcessProvider($instantRemoteProcessFactory, $executionerManager);

    $this->assertInstanceOf(RemoteProcessProvider::class, $remoteProcessProvider);
});

it('is able to create an instance of remote process manager', function () {
    $instantRemoteProcessFactory = Mockery::mock(InstantRemoteProcessFactory::class);
    $executionerManager = Mockery::mock(RemoteProcessExecutionerManager::class);

    $remoteProcessProvider = new RemoteProcessProvider($instantRemoteProcessFactory, $executionerManager);

    $server = Mockery::mock(Server::class);

    $remoteProcessManager = $remoteProcessProvider->forServer($server);

    $this->assertInstanceOf(RemoteProcessManager::class, $remoteProcessManager);
});
