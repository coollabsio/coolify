<?php

it('keeps git setup output out of docker compose raw by only emitting cat output on stdout', function () {
    $source = file_get_contents(__DIR__.'/../../app/Models/Application.php');

    expect($source)
        ->toContain('$setupCommand = "({$setupCommands->implode(\' && \')}) > {$escapedSetupLogFile} 2>&1 || { cat {$escapedSetupLogFile} >&2; exit 1; }";')
        ->toContain('$composeFileContent = instant_remote_process($commands, $this->destination->server);')
        ->toContain('$this->docker_compose_raw = $composeFileContent;')
        ->not->toContain('}) > /dev/null');
});

it('shell escapes sparse checkout paths, compose file path, and cleanup path', function () {
    $source = file_get_contents(__DIR__.'/../../app/Models/Application.php');

    expect($source)
        ->toContain('$escapedFileList = $fileList->map(fn (string $file) => escapeshellarg($file));')
        ->toContain('"git sparse-checkout set {$escapedFileList->implode(\' \')}"')
        ->toContain('$composeFilePath = ".$workdir$composeFile";')
        ->toContain('$escapedComposeFilePath = escapeshellarg($composeFilePath);')
        ->toContain('"cat {$escapedComposeFilePath}"')
        ->toContain('$escapedCheckoutDirectory = escapeshellarg($checkoutDirectory);')
        ->toContain('$setupLogFile = "/tmp/{$uuid}.git-setup.log";')
        ->toContain('"rm -rf {$escapedCheckoutDirectory} {$escapedSetupLogFile}"');
});
