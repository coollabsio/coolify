<?php

use App\Jobs\CheckForUpdatesJob;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Queue::fake();

    // The job reads the instance settings row (id 0) through instanceSettings().
    InstanceSettings::forceCreate(['id' => 0]);
    $this->settings = InstanceSettings::get();
});

afterEach(function () {
    @unlink(storage_path('app/versions.json'));
    Mockery::close();
});

it('keeps the local versions.json when the CDN serves an older Coolify version', function () {
    Http::fake(['*' => Http::response(['stable' => ['coolify' => ['version' => '4.0.0'], 'traefik' => ['v3.5' => '3.5.6']]], 200)]);
    File::put(storage_path('app/versions.json'), json_encode(['stable' => ['coolify' => ['version' => '4.0.10']]]));
    config(['constants.coolify.version' => '4.0.5']);

    Log::shouldReceive('warning')
        ->once()
        ->with('CDN served older Coolify version than cache', Mockery::type('array'));

    expect(refresh_versions_file())->toMatchArray(['coolify' => ['version' => '4.0.10']])
        ->and(json_decode(File::get(storage_path('app/versions.json')), true))->toBe(['stable' => ['coolify' => ['version' => '4.0.10']]]);
});

it('never offers a version below the running version', function () {
    Http::fake(['*' => Http::response(['stable' => ['coolify' => ['version' => '4.0.0']]], 200)]);
    config(['constants.coolify.version' => '4.0.10']);

    Log::shouldReceive('warning')
        ->once()
        ->with('Version downgrade prevented in CheckForUpdatesJob', Mockery::type('array'));

    expect(data_get(refresh_versions_file(), 'coolify.version'))->toBe('4.0.10');
});

it('keeps the CDN versions of the other components when it keeps the running Coolify version', function () {
    Http::fake([
        '*' => Http::response([
            'stable' => [
                'coolify' => ['version' => '4.0.0'],
                'traefik' => ['v3.6' => '3.6.999'],
                'sentinel' => ['version' => '1.999.0'],
            ],
        ], 200),
    ]);
    config(['constants.coolify.version' => '4.0.10']);

    $versions = refresh_versions_file();

    expect($versions['coolify'])->toBe(['version' => '4.0.10'])
        ->and($versions['traefik']['v3.6'])->toBe('3.6.999')
        ->and($versions['sentinel'])->toBe(['version' => '1.999.0']);
});

it('never offers an older version of a component than upstream.json pins', function (string $helper, string $sentinel, string $traefik) {
    $upstream = json_decode(File::get(base_path('upstream.json')), true);
    Http::fake([
        '*' => Http::response([
            'stable' => [
                'coolify' => ['version' => '4.0.10'],
                'helper' => ['version' => $helper],
                'sentinel' => ['version' => $sentinel],
                'sentinel-host' => ['version' => $sentinel, 'digest' => 'sha256:cdn'],
                'traefik' => ['v3.6' => $traefik],
            ],
        ], 200),
    ]);
    config(['constants.coolify.version' => '4.0.10']);

    $versions = refresh_versions_file();

    expect($versions['helper'])->toBe($upstream['helper']['v1'])
        ->and($versions['sentinel'])->toBe($upstream['sentinel']['v1'])
        ->and($versions['sentinel-host'])->toBe($upstream['sentinel-host']['v1'])
        ->and($versions['traefik']['v3.6'])->toBe($upstream['traefik']['v3.6']['version'])
        ->and($versions['traefik']['v3.7'])->toBe($upstream['traefik']['v3.7']['version']);
})->with([
    'an older version' => ['1.0.0', '1.0.0', '3.6.0'],
    'a version of another line' => ['2.0.0', '2.0.0', '3.7.999'],
]);

it('reads the versions of upstream.json until the first update check', function () {
    $versions = get_versions_data();

    expect($versions['helper']['version'])->toBe(config('constants.coolify.helper_version'))
        ->and($versions['sentinel']['version'])->toMatch('/^\d+\.\d+\.\d+$/')
        ->and($versions['sentinel-host']['digest'])->toStartWith('sha256:')
        ->and($versions['traefik']['v3.7'])->toStartWith('3.7.');
});

it('keeps the local versions.json when the CDN fetch fails', function () {
    Http::fake(['*' => Http::response('', 500)]);
    File::shouldReceive('replace')->never();
    Cache::shouldReceive('forget')->never();

    expect(refresh_versions_file())->toBeNull();
});

it('makes only one CDN request for a manual check, so the web request does not wait for retries', function () {
    Sleep::fake();
    Http::fake(['*' => Http::response('error code: 1200', 503, ['Retry-After' => '60'])]);

    (new CheckForUpdatesJob(cdnTries: 1))->handle();

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});
