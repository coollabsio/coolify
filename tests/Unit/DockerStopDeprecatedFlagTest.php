<?php

/**
 * Docker deprecated `--time` on `docker stop` / `docker restart` in v28.0.
 * Call sites must go through dockerStopCommand() so the flag is chosen from
 * the stored engine version (`--timeout` on 28+, `--time` before that or if unknown).
 *
 * @see https://github.com/coollabsio/coolify/issues/11244
 * @see https://github.com/coollabsio/coolify/issues/10791
 */
it('does not hardcode docker --time outside the version-aware helper', function () {
    $projectRoot = dockerStopDeprecatedFlagProjectRoot();
    $helperFile = $projectRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'helpers'.DIRECTORY_SEPARATOR.'docker.php';

    $offenders = collect([
        ...dockerStopDeprecatedFlagPhpFiles($projectRoot.DIRECTORY_SEPARATOR.'app'),
        ...dockerStopDeprecatedFlagPhpFiles($projectRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'helpers'),
    ])
        ->reject(fn (string $file): bool => $file === $helperFile)
        ->mapWithKeys(function (string $file) use ($projectRoot) {
            preg_match_all('/docker\s+(?:stop|restart)\s+--time\b/', file_get_contents($file), $matches);

            return [str_replace($projectRoot.DIRECTORY_SEPARATOR, '', $file) => $matches[0]];
        })
        ->filter(fn (array $matches): bool => $matches !== [])
        ->map(fn (array $matches, string $file): string => $file.': '.implode(', ', array_unique($matches)));

    expect($offenders->values()->implode("\n"))->toBe('');
});

it('builds application container stop commands from the stored docker version', function (string $relativePath) {
    $contents = file_get_contents(dockerStopDeprecatedFlagProjectRoot().DIRECTORY_SEPARATOR.$relativePath);

    expect($contents)
        ->toContain('dockerStopCommand(')
        ->not->toContain('docker stop --time=')
        ->not->toContain('docker stop -t $timeout');
})->with([
    'deployment graceful shutdown' => ['app/Jobs/ApplicationDeploymentJob.php'],
    'stop application' => ['app/Actions/Application/StopApplication.php'],
    'stop application on one server' => ['app/Actions/Application/StopApplicationOneServer.php'],
    'stop preview containers' => ['app/Livewire/Project/Application/Previews.php'],
]);

function dockerStopDeprecatedFlagProjectRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @return list<string>
 */
function dockerStopDeprecatedFlagPhpFiles(string $directory): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}
