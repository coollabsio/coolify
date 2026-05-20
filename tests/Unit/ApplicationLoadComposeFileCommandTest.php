<?php

use App\Models\Application;

it('keeps compose loader setup output out of stdout', function () {
    $application = new class extends Application
    {
        public function muteForTest(string $command, string $escapedLogFile): string
        {
            return $this->muteComposeLoaderCommand($command, $escapedLogFile);
        }
    };

    $command = $application->muteForTest('git clone https://example.com/repo.git .', "'/tmp/load-compose-file.log'");

    expect($command)
        ->toBe("(git clone https://example.com/repo.git .) > '/tmp/load-compose-file.log' 2>&1 || { cat '/tmp/load-compose-file.log' >&2; exit 1; }")
        ->toContain("> '/tmp/load-compose-file.log' 2>&1")
        ->toContain("cat '/tmp/load-compose-file.log' >&2")
        ->toContain('exit 1');
});
