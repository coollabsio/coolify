<?php

use App\Models\Application;

it('wraps compose loader git commands so only the compose file is written to stdout', function () {
    $application = new Application;
    $method = new ReflectionMethod(Application::class, 'silenceRemoteCommandOutput');
    $method->setAccessible(true);

    $command = $method->invoke(
        $application,
        'git clone https://example.com/acme/app.git .',
        '/tmp/coolify-test/git-output.log'
    );
    $escapedLogFile = escapeshellarg('/tmp/coolify-test/git-output.log');
    $expected = '(git clone https://example.com/acme/app.git .)'
        ." >> {$escapedLogFile} 2>&1"
        ." || { cat {$escapedLogFile} >&2; exit 1; }";

    expect($command)
        ->toBe($expected)
        ->toContain(">> {$escapedLogFile} 2>&1")
        ->toContain("cat {$escapedLogFile} >&2");
});

it('keeps loadComposeFile setup commands silent before cat reads the compose file', function () {
    $applicationFile = file_get_contents(__DIR__.'/../../app/Models/Application.php');
    $loadComposeFileStart = strpos($applicationFile, 'public function loadComposeFile');
    $parseContainerLabelsStart = strpos($applicationFile, 'public function parseContainerLabels');
    $loadComposeFile = substr($applicationFile, $loadComposeFileStart, $parseContainerLabelsStart - $loadComposeFileStart);

    expect($loadComposeFile)
        ->toContain('$this->silenceRemoteCommandOutput($cloneCommand, $gitOutputLog)')
        ->toContain("\$this->silenceRemoteCommandOutput('git sparse-checkout init', \$gitOutputLog)")
        ->toContain("\$this->silenceRemoteCommandOutput('git sparse-checkout init --cone', \$gitOutputLog)")
        ->toContain("\$this->silenceRemoteCommandOutput('git read-tree -mu HEAD', \$gitOutputLog)")
        ->toContain('"cat .$workdir$composeFile"');
});
