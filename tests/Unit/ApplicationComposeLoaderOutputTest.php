<?php

use App\Models\Application;

it('redirects compose loader setup output away from stdout', function () {
    $application = new class extends Application
    {
        public function wrapComposeLoaderCommand(string $command, string $outputLog): string
        {
            return $this->redirectComposeLoaderOutput($command, $outputLog);
        }
    };

    $wrappedCommand = $application->wrapComposeLoaderCommand(
        "git clone --no-checkout -b 'main' 'https://github.com/example/repo' 'checkout'",
        '/tmp/compose-load-test/compose-loader-output.log'
    );

    expect($wrappedCommand)->toBe(
        "(git clone --no-checkout -b 'main' 'https://github.com/example/repo' 'checkout') >> '/tmp/compose-load-test/compose-loader-output.log' 2>&1 || { cat '/tmp/compose-load-test/compose-loader-output.log' >&2; exit 1; }"
    );
});

it('keeps compose loader setup commands silent before catting the compose file', function () {
    $source = file_get_contents(__DIR__.'/../../app/Models/Application.php');

    expect($source)
        ->toContain("custom_base_dir: 'checkout'")
        ->toContain('$this->redirectComposeLoaderOutput($cloneCommand, $composeLoaderOutputLog)')
        ->toContain("'cd checkout'")
        ->toContain('$this->redirectComposeLoaderOutput(\'git sparse-checkout init --cone\', $composeLoaderOutputLog)')
        ->toContain('"cat .$workdir$composeFile"');
});
