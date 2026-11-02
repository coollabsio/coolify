<?php

use App\Actions\Node\ConfigureSentinelMetrics;
use App\Actions\Node\InstallSentinel;
use App\Models\Node;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

it('applies collection settings and restores the environment when restart fails', function (bool $enabled, bool $fails, bool $nonRoot) {
    $directory = sys_get_temp_dir().'/node-metrics-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    try {
        $environment = $directory.'/sentinel.env';
        $original = "TOKEN=unchanged\nCONTROL_PLANE_ENABLED=true\nCOLLECTOR_ENABLED=true\nSTORAGE_ENABLED=true\n";
        file_put_contents($environment, $original);
        file_put_contents($directory.'/systemctl', "#!/bin/sh\n".($fails ? 'exit 1' : 'exit 0')."\n");
        file_put_contents($directory.'/sudo', "#!/bin/sh\nexec \"\$@\"\n");
        chmod($directory.'/systemctl', 0700);
        chmod($directory.'/sudo', 0700);
        $script = str_replace('/etc/coolify/sentinel.env', $environment, ConfigureSentinelMetrics::configurationScript($enabled));
        $command = InstallSentinel::remoteCommand($script);
        if ($nonRoot) {
            $command = implode("\n", parseCommandsByLineForSudo(collect([$command]), new Node(['user' => 'deploy'])));
        }
        $process = new Process(['bash', '-c', $command], null, ['PATH' => $directory.':'.getenv('PATH')]);
        $process->run();
        expect($process->isSuccessful())->toBe(! $fails);
        $result = file_get_contents($environment);
        if ($fails) {
            expect($result)->toBe($original);
        } else {
            $value = $enabled ? 'true' : 'false';
            expect($result)->toContain('COLLECTOR_ENABLED='.$value, 'STORAGE_ENABLED='.$value, 'TOKEN=unchanged', 'CONTROL_PLANE_ENABLED=true');
        }
    } finally {
        File::deleteDirectory($directory);
    }
})->with([
    [false, false, false],
    [true, false, false],
    [false, true, false],
    [false, false, true],
    [true, false, true],
    [false, true, true],
]);
