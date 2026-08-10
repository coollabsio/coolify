<?php

use App\Http\Controllers\Api\Concerns\HandlesTerminalApi;
use Illuminate\Contracts\Process\ProcessResult;

test('oversized output does not stop the command before it finishes', function () {
    $runner = new class
    {
        use HandlesTerminalApi;

        public function run(string $command): ProcessResult
        {
            return $this->runTerminalProcess($command, 10);
        }
    };

    $markerPath = tempnam(sys_get_temp_dir(), 'terminal-process-');
    unlink($markerPath);

    $script = 'fwrite(STDOUT, str_repeat("a", 70000)); usleep(100000); file_put_contents('.var_export($markerPath, true).', "finished");';
    $command = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script);

    try {
        $result = $runner->run($command);

        expect($result->exitCode())->toBe(0)
            ->and(file_get_contents($markerPath))->toBe('finished');
    } finally {
        @unlink($markerPath);
    }
});
