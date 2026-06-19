<?php

use Illuminate\Support\Facades\Http;

function createSyncBunnyFailingBinary(string $binDir, string $name): void
{
    file_put_contents("{$binDir}/{$name}", <<<'SH'
#!/bin/sh
printf '%s %s\n' "$(basename "$0")" "$*" >> "$SYNC_BUNNY_TEST_LOG"
exit 1
SH);
    chmod("{$binDir}/{$name}", 0755);
}

it('syncs nightly versions to BunnyCDN without creating a GitHub PR', function () {
    Http::fake([
        'storage.bunnycdn.com/*' => Http::response([], 201),
        'api.bunny.net/purge*' => Http::response([], 200),
    ]);

    $binDir = sys_get_temp_dir().'/sync-bunny-bin-'.uniqid();
    $logFile = sys_get_temp_dir().'/sync-bunny-'.uniqid().'.log';

    mkdir($binDir, 0755, true);
    createSyncBunnyFailingBinary($binDir, 'gh');
    createSyncBunnyFailingBinary($binDir, 'git');

    $originalPath = getenv('PATH') ?: '';
    putenv("PATH={$binDir}:{$originalPath}");
    putenv("SYNC_BUNNY_TEST_LOG={$logFile}");

    try {
        $this->artisan('sync:bunny --release --nightly')
            ->expectsConfirmation('Are you sure you want to proceed?', 'yes')
            ->expectsOutputToContain('BunnyCDN sync: ✓ Complete')
            ->doesntExpectOutputToContain('GitHub PR')
            ->assertExitCode(0);
    } finally {
        putenv("PATH={$originalPath}");
        putenv('SYNC_BUNNY_TEST_LOG');
    }

    expect(file_exists($logFile))->toBeFalse();

    Http::assertSent(fn ($request) => $request->url() === 'https://storage.bunnycdn.com/coolcdn/coolify-nightly/versions.json');
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.bunny.net/purge')
        && $request['url'] === 'https://cdn.coollabs.io/coolify-nightly/versions.json');
});

it('syncs postgres upgrade script to BunnyCDN during full sync', function () {
    Http::fake([
        'https://cdn.coollabs.io/coolify/*' => Http::response('', 404),
        'https://storage.bunnycdn.com/*' => Http::response([], 201),
        'https://api.bunny.net/purge*' => Http::response([], 200),
    ]);

    $this->artisan('sync:bunny')
        ->expectsConfirmation('Are you sure you want to sync?', 'yes')
        ->expectsOutputToContain('BunnyCDN sync: Complete')
        ->assertExitCode(0);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'https://storage.bunnycdn.com/coolcdn/coolify/upgrade-postgres.sh');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.bunny.net/purge')
        && $request['url'] === 'https://cdn.coollabs.io/coolify/upgrade-postgres.sh');
});
