<?php

use App\Jobs\CheckForUpdatesJob;
use App\Models\InstanceSettings;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    InstanceSettings::forceCreate([
        'id' => 0,
        'update_channel' => 'stable',
    ]);
});

afterEach(function () {
    Mockery::close();
});

it('has correct job configuration', function () {
    $job = new CheckForUpdatesJob;

    $interfaces = class_implements($job);
    expect($interfaces)->toContain(ShouldQueue::class);
    expect($interfaces)->toContain(ShouldBeEncrypted::class);
});

it('uses max of CDN and cache versions', function () {
    // CDN has older version
    Http::fake([
        '*' => Http::response([
            'coolify' => ['v4' => ['version' => '4.0.0']],
            'traefik' => ['v3.5' => '3.5.6'],
        ], 200),
    ]);

    // Cache has newer version
    File::shouldReceive('exists')
        ->with(base_path('versions.json'))
        ->andReturn(true);

    File::shouldReceive('get')
        ->with(base_path('versions.json'))
        ->andReturn(json_encode(['coolify' => ['v4' => ['version' => '4.0.10']]]));

    File::shouldReceive('put')
        ->once()
        ->with(base_path('versions.json'), Mockery::on(function ($json) {
            $data = json_decode($json, true);

            // Should use cached version (4.0.10), not CDN version (4.0.0)
            return $data['coolify']['v4']['version'] === '4.0.10';
        }));

    Cache::shouldReceive('forget')->once();

    config(['constants.coolify.version' => '4.0.5']);

    $job = new CheckForUpdatesJob;
    $job->handle();
});

it('never downgrades from current running version', function () {
    // CDN has older version
    Http::fake([
        '*' => Http::response([
            'coolify' => ['v4' => ['version' => '4.0.0']],
            'traefik' => ['v3.5' => '3.5.6'],
        ], 200),
    ]);

    // Cache also has older version
    File::shouldReceive('exists')
        ->with(base_path('versions.json'))
        ->andReturn(true);

    File::shouldReceive('get')
        ->with(base_path('versions.json'))
        ->andReturn(json_encode(['coolify' => ['v4' => ['version' => '4.0.5']]]));

    File::shouldReceive('put')
        ->once()
        ->with(base_path('versions.json'), Mockery::on(function ($json) {
            $data = json_decode($json, true);

            // Should use running version (4.0.10), not CDN (4.0.0) or cache (4.0.5)
            return $data['coolify']['v4']['version'] === '4.0.10';
        }));

    Cache::shouldReceive('forget')->once();

    // Running version is newest
    config(['constants.coolify.version' => '4.0.10']);

    Log::shouldReceive('warning')
        ->once()
        ->with('Version downgrade prevented in CheckForUpdatesJob', Mockery::type('array'));

    Log::shouldReceive('warning')
        ->once()
        ->with('CDN served older Coolify version than cache', Mockery::type('array'));

    $job = new CheckForUpdatesJob;
    $job->handle();
});

it('uses data_set for safe version mutation', function () {
    Http::fake([
        '*' => Http::response([
            'coolify' => ['v4' => ['version' => '4.0.10']],
        ], 200),
    ]);

    File::shouldReceive('exists')->andReturn(false);
    File::shouldReceive('put')->once();
    Cache::shouldReceive('forget')->once();

    config(['constants.coolify.version' => '4.0.5']);

    $job = new CheckForUpdatesJob;

    // Should not throw even if structure is unexpected
    // data_set() handles nested path creation
    $job->handle();
})->skip('Needs better mock setup for instanceSettings');

it('preserves other component versions when preventing Coolify downgrade', function () {
    // CDN has older Coolify but newer Traefik
    Http::fake([
        '*' => Http::response([
            'coolify' => ['v4' => ['version' => '4.0.0']],
            'traefik' => ['v3.6' => '3.6.2'],
            'sentinel' => ['version' => '1.0.5'],
        ], 200),
    ]);

    File::shouldReceive('exists')->andReturn(true);
    File::shouldReceive('get')
        ->andReturn(json_encode([
            'coolify' => ['v4' => ['version' => '4.0.5']],
            'traefik' => ['v3.5' => '3.5.6'],
        ]));

    File::shouldReceive('put')
        ->once()
        ->with(base_path('versions.json'), Mockery::on(function ($json) {
            $data = json_decode($json, true);
            // Coolify should use running version
            expect($data['coolify']['v4']['version'])->toBe('4.0.10');
            // Traefik should use CDN version (newer)
            expect($data['traefik']['v3.6'])->toBe('3.6.2');
            // Sentinel should use CDN version
            expect($data['sentinel']['version'])->toBe('1.0.5');

            return true;
        }));

    Cache::shouldReceive('forget')->once();

    config(['constants.coolify.version' => '4.0.10']);

    Log::shouldReceive('warning')
        ->once()
        ->with('CDN served older Coolify version than cache', Mockery::type('array'));

    Log::shouldReceive('warning')
        ->once()
        ->with('Version downgrade prevented in CheckForUpdatesJob', Mockery::type('array'));

    $job = new CheckForUpdatesJob;
    $job->handle();
});
