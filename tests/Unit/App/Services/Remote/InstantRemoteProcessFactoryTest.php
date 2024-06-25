<?php

use App\Services\Remote\InstantRemoteProcessFactory;
use App\Services\Remote\RemoteCommandGeneratorService;
use App\Services\Remote\SshCommandGeneratorService;

it('is able to create an instance of instant remote process factory', function () {
    $remoteCommandGenerator = Mockery::mock(RemoteCommandGeneratorService::class);
    $sshCommandFactory = Mockery::mock(SshCommandGeneratorService::class);

    $instantRemoteProcessFactory = new InstantRemoteProcessFactory($remoteCommandGenerator, $sshCommandFactory);

    expect($instantRemoteProcessFactory)->toBeInstanceOf(InstantRemoteProcessFactory::class);
});
