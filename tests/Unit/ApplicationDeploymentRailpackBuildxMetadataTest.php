<?php

it('persists buildx metadata between the helper container and host cleanup', function () {
    $sourceFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    expect($sourceFile)
        ->toContain('mkdir -p {$this->serverUserHomeDir}/.docker/buildx')
        ->toContain('-v {$this->serverUserHomeDir}/.docker/buildx:/root/.docker/buildx');

    expect(substr_count($sourceFile, '{$buildxMetadataVolume} -v /var/run/docker.sock:/var/run/docker.sock'))->toBe(3);
});
