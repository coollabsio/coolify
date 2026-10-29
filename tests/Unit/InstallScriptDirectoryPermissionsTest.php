<?php

/**
 * Runs the /data/coolify directory setup of the install script against a temporary data root.
 * chown is replaced with a logger because changing the owner to UID 9999 needs root.
 *
 * @param  bool  $completeRun  false runs only the setup before step 1, as when a check or download fails
 * @return list<string> the paths that chown received
 */
function runInstallScriptDirectorySetup(string $scriptPath, string $dataRoot, bool $completeRun = true): array
{
    $script = file_get_contents(dirname(__DIR__, 2).'/'.$scriptPath);
    preg_match('/^# Resource directories.*?^mkdir -p \/data\/coolify\/source$/ms', $script, $matches);
    expect($matches)->not->toBeEmpty();

    $setup = $matches[0];
    if ($completeRun) {
        $setup .= "\ncreate_coolify_directories\nset_coolify_directory_permissions";
    }
    $setup = str_replace('/data/coolify', $dataRoot, $setup);
    $chownLog = "{$dataRoot}.chown";
    touch($chownLog);
    $process = proc_open(
        ['bash', '-c', "set -e\nchown() { printf '%s\\n' \"\$@\" >> ".escapeshellarg($chownLog)."; }\n{$setup}"],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $output);

    $chownedPaths = array_filter(file($chownLog, FILE_IGNORE_NEW_LINES), fn (string $argument) => str_starts_with($argument, '/'));
    unlink($chownLog);

    return array_values(array_map(fn (string $path) => str_replace($dataRoot, '/data/coolify', $path), $chownedPaths));
}

function installScriptFileMode(string $path): string
{
    clearstatcache(true, $path);

    return substr(sprintf('%o', fileperms($path)), -4);
}

$installScripts = [
    'stable install' => ['scripts/install.sh', ['source', 'ssh', 'images']],
    'nightly install' => ['other/nightly/install.sh', ['source', 'ssh', 'images']],
];
$resourceDirectories = ['applications', 'databases', 'backups', 'services', 'proxy', 'sentinel'];

it('creates the data directories as uid 9999 with mode 700 on a fresh install', function (string $scriptPath, array $coolifyDirectories) use ($resourceDirectories) {
    $dataRoot = sys_get_temp_dir().'/coolify-install-permissions-'.bin2hex(random_bytes(6));

    try {
        $chownedPaths = runInstallScriptDirectorySetup($scriptPath, $dataRoot);

        foreach (['', ...array_map(fn ($directory) => "/{$directory}", $resourceDirectories), '/proxy/dynamic'] as $directory) {
            expect($chownedPaths)->toContain("/data/coolify{$directory}")
                ->and(installScriptFileMode("{$dataRoot}{$directory}"))->toBe('0700');
        }
        foreach ($coolifyDirectories as $directory) {
            expect($chownedPaths)->toContain("/data/coolify/{$directory}")
                ->and(installScriptFileMode("{$dataRoot}/{$directory}"))->toBe('0700');
        }
    } finally {
        exec('rm -rf '.escapeshellarg($dataRoot));
    }
})->with($installScripts);

it('keeps existing resource directories and their content unchanged on an upgrade', function (string $scriptPath, array $coolifyDirectories) use ($resourceDirectories) {
    $dataRoot = sys_get_temp_dir().'/coolify-install-permissions-'.bin2hex(random_bytes(6));

    try {
        // A non-root server user setup: the SSH user owns the tree with mode 750.
        mkdir($dataRoot, 0750);
        chmod($dataRoot, 0750);
        foreach ([...$resourceDirectories, 'proxy/dynamic', ...$coolifyDirectories] as $directory) {
            mkdir("{$dataRoot}/{$directory}/nested", 0755, true);
            chmod("{$dataRoot}/{$directory}", 0750);
            touch("{$dataRoot}/{$directory}/nested/file");
            chmod("{$dataRoot}/{$directory}/nested/file", 0644);
        }

        $chownedPaths = runInstallScriptDirectorySetup($scriptPath, $dataRoot);

        expect($chownedPaths)->toBe(array_map(fn ($directory) => "/data/coolify/{$directory}", $coolifyDirectories))
            ->and(installScriptFileMode($dataRoot))->toBe('0750');
        foreach ([...$resourceDirectories, 'proxy/dynamic'] as $directory) {
            expect(installScriptFileMode("{$dataRoot}/{$directory}"))->toBe('0750')
                ->and(installScriptFileMode("{$dataRoot}/{$directory}/nested"))->toBe('0755')
                ->and(installScriptFileMode("{$dataRoot}/{$directory}/nested/file"))->toBe('0644');
        }
        foreach ($coolifyDirectories as $directory) {
            expect(installScriptFileMode("{$dataRoot}/{$directory}/nested"))->toBe('0700')
                ->and(installScriptFileMode("{$dataRoot}/{$directory}/nested/file"))->toBe('0700');
        }
    } finally {
        exec('rm -rf '.escapeshellarg($dataRoot));
    }
})->with($installScripts);

it('does not change an existing data directory before the checks and downloads succeed', function (string $scriptPath, array $coolifyDirectories) {
    $dataRoot = sys_get_temp_dir().'/coolify-install-permissions-'.bin2hex(random_bytes(6));

    try {
        mkdir($dataRoot, 0750);
        chmod($dataRoot, 0750);
        foreach ($coolifyDirectories as $directory) {
            mkdir("{$dataRoot}/{$directory}/nested", 0755, true);
            chmod("{$dataRoot}/{$directory}", 0750);
            touch("{$dataRoot}/{$directory}/nested/file");
            chmod("{$dataRoot}/{$directory}/nested/file", 0644);
        }

        $chownedPaths = runInstallScriptDirectorySetup($scriptPath, $dataRoot, completeRun: false);

        expect($chownedPaths)->toBe([])
            ->and(installScriptFileMode($dataRoot))->toBe('0750');
        foreach ($coolifyDirectories as $directory) {
            expect(installScriptFileMode("{$dataRoot}/{$directory}"))->toBe('0750')
                ->and(installScriptFileMode("{$dataRoot}/{$directory}/nested"))->toBe('0755')
                ->and(installScriptFileMode("{$dataRoot}/{$directory}/nested/file"))->toBe('0644');
        }
    } finally {
        exec('rm -rf '.escapeshellarg($dataRoot));
    }
})->with($installScripts);

it('changes the data directories only after the checks and downloads', function (string $scriptPath) {
    $script = file_get_contents(dirname(__DIR__, 2).'/'.$scriptPath);
    $downloadStep = strpos($script, 'step_start "Downloading required files from CDN"');

    expect($script)
        ->not->toContain("chown -R 9999:root /data/coolify\n")
        ->not->toContain("chmod -R 700 /data/coolify\n")
        ->and(substr_count($script, "\ncreate_coolify_directories\n"))->toBe(1)
        ->and(substr_count($script, "\nset_coolify_directory_permissions\n"))->toBe(1)
        ->and(strpos($script, "\ncreate_coolify_directories\n"))->toBeGreaterThan($downloadStep)
        ->and(strpos($script, "\nset_coolify_directory_permissions\n"))->toBeGreaterThan($downloadStep);
})->with(array_map(fn (array $script) => $script[0], $installScripts));
