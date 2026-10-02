<?php

use App\Console\Commands\SyncCdn;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function createFakeSyncCdnBinary(string $binDir, string $name, string $contents): void
{
    file_put_contents("{$binDir}/{$name}", $contents);
    chmod("{$binDir}/{$name}", 0755);
}

it('does not expose the removed sync options', function () {
    $definition = Artisan::all()['sync:cdn']->getDefinition();

    expect($definition->hasOption('bunny'))->toBeFalse()
        ->and($definition->hasOption('github-releases'))->toBeFalse()
        ->and($definition->hasOption('release'))->toBeFalse()
        ->and($definition->hasOption('nightly'))->toBeFalse()
        ->and($definition->hasOption('templates'))->toBeFalse();
});

it('loads service templates from the Coollabs CDN', function () {
    expect(config('constants.services.official'))
        ->toBe('https://cdn.coollabs.io/coolify/service-templates-latest.json');
});

it('only removes validated Coolify CDN temporary directories', function () {
    $command = new class extends SyncCdn
    {
        public function removeDirectory(string $path): void
        {
            $this->removeTemporaryDirectory($path);
        }
    };

    $invalidDirectory = sys_get_temp_dir().'/unrelated-directory-'.uniqid();
    $validDirectory = sys_get_temp_dir().'/coollabs-cdn-files-'.uniqid();
    mkdir($invalidDirectory);
    mkdir($validDirectory);

    $command->removeDirectory('');
    $command->removeDirectory($invalidDirectory);
    $command->removeDirectory($validDirectory);

    expect($invalidDirectory)->toBeDirectory()
        ->and($validDirectory)->not->toBeDirectory();

    rmdir($invalidDirectory);
});

it('selects the environment and release files to sync to GitHub', function (string $targetDirectory, string $environment, array $selectedBasenames) {
    Http::fake([
        'api.github.com/repos/coollabsio/coolify/releases*' => Http::response([], 200),
    ]);

    $binDir = sys_get_temp_dir().'/sync-cdn-bin-'.uniqid();
    $logFile = sys_get_temp_dir().'/sync-cdn-'.uniqid().'.log';

    mkdir($binDir, 0755, true);

    createFakeSyncCdnBinary($binDir, 'gh', <<<'SH'
#!/bin/sh
printf 'gh %s\n' "$*" >> "$SYNC_CDN_TEST_LOG"
if [ "$1" = "repo" ] && [ "$2" = "clone" ]; then
    mkdir -p "$4"
fi
exit 0
SH);

    createFakeSyncCdnBinary($binDir, 'git', <<<'SH'
#!/bin/sh
printf 'git %s\n' "$*" >> "$SYNC_CDN_TEST_LOG"
if [ "$1" = "status" ]; then
    printf 'M json/releases.json\n'
fi
if [ "$1" = "diff" ]; then
    if [ -f json/coolify/nightly/releases.json ]; then
        printf 'json/coolify/nightly/releases.json\n'
    else
        printf 'json/coolify/releases.json\n'
    fi
fi
exit 0
SH);

    $originalPath = getenv('PATH') ?: '';
    putenv("PATH={$binDir}:{$originalPath}");
    putenv("SYNC_CDN_TEST_LOG={$logFile}");

    $allBasenames = [
        'releases.json',
        'versions.json',
        'docker-compose.yml',
        'docker-compose.prod.yml',
        '.env.production',
        'install.sh',
        'upgrade.sh',
        'upgrade-postgres.sh',
        'service-templates-latest.json',
    ];
    $allTargets = array_map(fn (string $file) => "$targetDirectory/$file", $allBasenames);
    $selectedTargets = array_map(fn (string $file) => "$targetDirectory/$file", $selectedBasenames);

    try {
        $this->artisan('sync:cdn')
            ->expectsChoice('Which environment would you like to sync?', $environment, [
                'production' => 'Production',
                'nightly' => 'Nightly',
            ])
            ->expectsChoice('Which files would you like to sync?', $selectedTargets, $allTargets)
            ->assertExitCode(0);
    } finally {
        putenv("PATH={$originalPath}");
        putenv('SYNC_CDN_TEST_LOG');
    }

    $log = file_get_contents($logFile);

    expect($log)
        ->toContain('gh pr create --repo coollabsio/coollabs-cdn')
        ->not->toContain('coollabsio/coolify-cdn');

    foreach ($selectedTargets as $selectedTarget) {
        expect($log)->toContain($selectedTarget);
    }

    foreach (array_diff($allTargets, $selectedTargets) as $unselectedTarget) {
        expect($log)->not->toContain($unselectedTarget);
    }

    $pullRequestCommand = substr($log, strrpos($log, 'gh pr create'));

    expect($pullRequestCommand)
        ->toContain("$targetDirectory/releases.json")
        ->not->toContain("$targetDirectory/versions.json");

    Http::assertSentCount(1);
})->with([
    'select production files' => ['json/coolify', 'production', ['releases.json', 'versions.json']],
    'select nightly with all files selected by default' => ['json/coolify/nightly', 'nightly', [
        'releases.json',
        'versions.json',
        'docker-compose.yml',
        'docker-compose.prod.yml',
        '.env.production',
        'install.sh',
        'upgrade.sh',
        'upgrade-postgres.sh',
        'service-templates-latest.json',
    ]],
]);
