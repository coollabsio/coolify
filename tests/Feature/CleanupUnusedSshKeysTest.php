<?php

use App\Jobs\CleanupUnusedSshKeys;
use App\Jobs\RotatePrivateKeyJob;
use App\Models\Application;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('ssh-keys');
    Cache::flush();
    $this->freezeTime();
});

function unusedSshCleanupKey(string $uuid): PrivateKey
{
    return PrivateKey::withoutEvents(fn () => PrivateKey::forceCreate([
        'uuid' => $uuid, 'name' => 'Cleanup test', 'private_key' => 'encrypted test key', 'team_id' => 1,
    ]));
}

function unusedSshCleanupFile(string $uuid): string
{
    $filename = 'ssh_key@'.$uuid;
    Storage::disk('ssh-keys')->put($filename, 'test key');
    touch(Storage::disk('ssh-keys')->path($filename), now()->subDays(2)->timestamp);

    return $filename;
}

it('removes unused and orphaned files but keeps encrypted database keys', function () {
    $key = unusedSshCleanupKey('unused');
    $unused = unusedSshCleanupFile($key->uuid);
    $orphan = unusedSshCleanupFile('orphan');
    Storage::disk('ssh-keys')->put('unrelated', 'keep');

    app(CleanupUnusedSshKeys::class)->handle();

    Storage::disk('ssh-keys')->assertMissing([$unused, $orphan]);
    Storage::disk('ssh-keys')->assertExists('unrelated');
    $this->assertModelExists($key);
    expect($key->fresh()->private_key)->toBe('encrypted test key');
});

it('keeps recent files and both keys of a running rotation', function () {
    $old = unusedSshCleanupKey('old');
    $new = unusedSshCleanupKey('new');
    $files = [unusedSshCleanupFile('old'), unusedSshCleanupFile('new')];
    Storage::disk('ssh-keys')->put('ssh_key@recent', 'keep');
    Cache::put(RotatePrivateKeyJob::cacheKey($old), ['status' => 'running', 'new_private_key_id' => $new->id]);

    app(CleanupUnusedSshKeys::class)->handle();

    Storage::disk('ssh-keys')->assertExists([...$files, 'ssh_key@recent']);
});

it('does not delete a key while its writer holds the lock', function () {
    $file = unusedSshCleanupFile('locked');
    $lock = fopen(Storage::disk('ssh-keys')->path($file).'.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        app(CleanupUnusedSshKeys::class)->handle();
        Storage::disk('ssh-keys')->assertExists($file);
    } finally {
        fclose($lock);
    }
});

it('keeps a key referenced by a server', function () {
    $key = unusedSshCleanupKey('server-key');
    $file = unusedSshCleanupFile($key->uuid);
    Server::withoutEvents(fn () => Server::factory()->create([
        'uuid' => 'test-server', 'private_key_id' => $key->id, 'team_id' => 1,
    ]));

    app(CleanupUnusedSshKeys::class)->handle();

    Storage::disk('ssh-keys')->assertExists($file);
});

it('keeps a key referenced by an application or a git source', function (Closure $reference) {
    $key = unusedSshCleanupKey('referenced-key');
    $file = unusedSshCleanupFile($key->uuid);
    $reference($key);

    app(CleanupUnusedSshKeys::class)->handle();

    Storage::disk('ssh-keys')->assertExists($file);
})->with([
    'application' => [fn (PrivateKey $key) => Application::withoutEvents(fn () => Application::factory()->create(['uuid' => 'cleanup-app', 'private_key_id' => $key->id]))],
    'GitHub app' => [fn (PrivateKey $key) => GithubApp::withoutEvents(fn () => GithubApp::forceCreate([
        'uuid' => 'cleanup-github', 'name' => 'github', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com', 'team_id' => 1, 'private_key_id' => $key->id,
    ]))],
    'GitLab app' => [fn (PrivateKey $key) => GitlabApp::withoutEvents(fn () => GitlabApp::forceCreate([
        'uuid' => 'cleanup-gitlab', 'name' => 'gitlab', 'api_url' => 'https://gitlab.example.com/api/v4', 'html_url' => 'https://gitlab.example.com', 'team_id' => 1, 'private_key_id' => $key->id,
    ]))],
    'Gitea app' => [fn (PrivateKey $key) => GiteaApp::withoutEvents(fn () => GiteaApp::forceCreate([
        'uuid' => 'cleanup-gitea', 'name' => 'gitea', 'api_url' => 'https://gitea.example.com/api/v1', 'html_url' => 'https://gitea.example.com', 'team_id' => 1, 'private_key_id' => $key->id,
    ]))],
]);

it('removes the file of a key that only a deleted server references', function () {
    $key = unusedSshCleanupKey('deleted-server-key');
    $file = unusedSshCleanupFile($key->uuid);
    $server = Server::withoutEvents(fn () => Server::factory()->create([
        'uuid' => 'deleted-server', 'private_key_id' => $key->id, 'team_id' => 1,
    ]));
    Server::withoutEvents(fn () => $server->delete());

    app(CleanupUnusedSshKeys::class)->handle();

    Storage::disk('ssh-keys')->assertMissing($file);
});

it('uses a fixed number of queries for any number of key files', function () {
    foreach (range(1, 40) as $id) {
        $key = unusedSshCleanupKey('bounded-'.$id);
        unusedSshCleanupFile($key->uuid);
        if ($id % 2 === 0) {
            Server::withoutEvents(fn () => Server::factory()->create([
                'uuid' => 'bounded-server-'.$id, 'private_key_id' => $key->id, 'team_id' => 1,
            ]));
        }
    }
    $queries = 0;
    (new PrivateKey)->getConnection()->listen(function () use (&$queries) {
        $queries++;
    });

    app(CleanupUnusedSshKeys::class)->handle();

    expect($queries)->toBeLessThanOrEqual(6);
    $remaining = array_values(array_filter(Storage::disk('ssh-keys')->files(), fn ($file) => ! str_ends_with($file, '.lock')));
    expect($remaining)->toHaveCount(20);
});

it('cleans up 10000 local key files with mixed usage', function () {
    $encryptedKey = encrypt(str_repeat('test-key-material', 240));
    for ($batch = 0; $batch < 20; $batch++) {
        $keys = [];
        $servers = [];
        for ($offset = 1; $offset <= 500; $offset++) {
            $id = $batch * 500 + $offset;
            $keys[] = [
                'id' => $id, 'uuid' => 'load-'.$id, 'name' => 'Load test '.$id,
                'private_key' => $encryptedKey, 'team_id' => 1,
            ];
            unusedSshCleanupFile('load-'.$id);
            if ($id <= 5000) {
                $servers[] = [
                    'uuid' => 'load-server-'.$id, 'name' => 'Load server '.$id,
                    'ip' => '127.0.0.1', 'private_key_id' => $id, 'team_id' => 1,
                ];
            }
        }
        PrivateKey::query()->insert($keys);
        if ($servers !== []) {
            Server::query()->insert($servers);
        }
    }

    $queries = 0;
    $cacheReads = 0;
    $connection = (new PrivateKey)->getConnection();
    $connection->listen(function () use (&$queries) {
        $queries++;
    });
    app('events')->listen(CacheMissed::class, function () use (&$cacheReads) {
        $cacheReads++;
    });
    $memoryBefore = memory_get_usage(true);
    memory_reset_peak_usage();
    $started = hrtime(true);

    app(CleanupUnusedSshKeys::class)->handle();

    $seconds = (hrtime(true) - $started) / 1e9;
    $peakExtraMiB = (memory_get_peak_usage(true) - $memoryBefore) / 1048576;
    $jobQueries = $queries;
    $files = Storage::disk('ssh-keys')->files();
    $remaining = array_values(array_filter($files, fn ($file) => ! str_ends_with($file, '.lock')));
    expect($remaining)->toHaveCount(5000);
    foreach (range(1, 10000) as $id) {
        expect(in_array('ssh_key@load-'.$id, $remaining, true))->toBe($id <= 5000);
    }
    expect(PrivateKey::query()->count())->toBe(10000);
    fwrite(STDERR, sprintf(
        "\nSSH cleanup load test: %.3f s, %.1f MiB extra peak, %d SQL queries, %d cache misses, 5000 deleted, 5000 retained, %d lock files\n",
        $seconds, $peakExtraMiB, $jobQueries, $cacheReads, count($files) - count($remaining),
    ));
})->skip(fn () => getenv('SSH_KEY_CLEANUP_LOAD_TEST') !== '1', 'Opt-in load test');
