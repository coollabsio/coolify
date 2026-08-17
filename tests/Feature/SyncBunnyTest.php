<?php

use App\Console\Commands\SyncBunny;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function createFakeSyncBunnyBinary(string $binDir, string $name, string $contents): void
{
    file_put_contents("{$binDir}/{$name}", $contents);
    chmod("{$binDir}/{$name}", 0755);
}

it('only exposes the BunnyCDN legacy sync option', function () {
    $definition = Artisan::all()['sync:bunny']->getDefinition();

    expect($definition->hasOption('bunny'))->toBeTrue()
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
    $command = new class extends SyncBunny
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

it('syncs full files to BunnyCDN only when explicitly requested', function () {
    Http::fake([
        'https://cdn.coollabs.io/coolify/*' => Http::response('', 404),
        'https://storage.bunnycdn.com/*' => Http::response([], 201),
        'https://api.bunny.net/purge*' => Http::response([], 200),
    ]);

    $binDir = sys_get_temp_dir().'/sync-bunny-bin-'.uniqid();
    $logFile = sys_get_temp_dir().'/sync-bunny-'.uniqid().'.log';

    mkdir($binDir, 0755, true);

    createFakeSyncBunnyBinary($binDir, 'gh', <<<'SH'
#!/bin/sh
printf 'gh %s\n' "$*" >> "$SYNC_BUNNY_TEST_LOG"
if [ "$1" = "repo" ] && [ "$2" = "clone" ]; then
    mkdir -p "$4/scripts"
fi
exit 0
SH);

    createFakeSyncBunnyBinary($binDir, 'git', <<<'SH'
#!/bin/sh
printf 'git %s\n' "$*" >> "$SYNC_BUNNY_TEST_LOG"
if [ "$1" = "status" ]; then
    printf 'M scripts/upgrade-postgres.sh\n'
fi
exit 0
SH);

    $originalPath = getenv('PATH') ?: '';
    putenv("PATH={$binDir}:{$originalPath}");
    putenv("SYNC_BUNNY_TEST_LOG={$logFile}");

    try {
        $this->artisan('sync:bunny --bunny')
            ->expectsChoice('Which environment would you like to sync?', 'production', [
                'production' => 'Production',
                'nightly' => 'Nightly',
            ])
            ->expectsConfirmation('Are you sure you want to sync?', 'yes')
            ->assertExitCode(0);
    } finally {
        putenv("PATH={$originalPath}");
        putenv('SYNC_BUNNY_TEST_LOG');
    }

    $log = file_exists($logFile) ? file_get_contents($logFile) : '';

    expect($log)
        ->not->toContain('gh repo clone')
        ->not->toContain('gh pr create')
        ->not->toContain('coollabsio/coolify-cdn');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'https://storage.bunnycdn.com/coolcdn/coolify/upgrade-postgres.sh');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.bunny.net/purge')
        && $request['url'] === 'https://cdn.coollabs.io/coolify/upgrade-postgres.sh');
});

it('selects the environment and release files to sync to GitHub', function (string $targetDirectory, string $environment, array $selectedBasenames) {
    Http::fake([
        'api.github.com/repos/coollabsio/coolify/releases*' => Http::response([], 200),
    ]);

    $binDir = sys_get_temp_dir().'/sync-bunny-bin-'.uniqid();
    $logFile = sys_get_temp_dir().'/sync-bunny-'.uniqid().'.log';

    mkdir($binDir, 0755, true);

    createFakeSyncBunnyBinary($binDir, 'gh', <<<'SH'
#!/bin/sh
printf 'gh %s\n' "$*" >> "$SYNC_BUNNY_TEST_LOG"
if [ "$1" = "repo" ] && [ "$2" = "clone" ]; then
    mkdir -p "$4"
fi
exit 0
SH);

    createFakeSyncBunnyBinary($binDir, 'git', <<<'SH'
#!/bin/sh
printf 'git %s\n' "$*" >> "$SYNC_BUNNY_TEST_LOG"
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
    putenv("SYNC_BUNNY_TEST_LOG={$logFile}");

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
        $this->artisan('sync:bunny')
            ->expectsChoice('Which environment would you like to sync?', $environment, [
                'production' => 'Production',
                'nightly' => 'Nightly',
            ])
            ->expectsChoice('Which files would you like to sync?', $selectedTargets, $allTargets)
            ->assertExitCode(0);
    } finally {
        putenv("PATH={$originalPath}");
        putenv('SYNC_BUNNY_TEST_LOG');
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
