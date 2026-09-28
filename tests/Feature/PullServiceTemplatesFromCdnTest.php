<?php

use App\Jobs\PullTemplatesFromCDN;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Cache::flush();
});

it('stores the CDN service templates bundle in shared cache and local file', function () {
    $payload = [
        'buzz' => [
            'category' => 'messaging',
            'documentation' => 'https://github.com/block/buzz',
            'compose' => '',
            'slogan' => 'Buzz',
            'tags' => null,
            'logo' => 'svgs/buzz.svg',
            'minversion' => '0.0.0',
            'template_last_updated_at' => '2026-07-23T18:52:29+02:00',
        ],
        'activepieces' => [
            'category' => 'automation',
            'documentation' => 'https://coolify.io/docs',
            'compose' => '',
            'slogan' => 'Activepieces',
            'tags' => null,
            'logo' => 'images/default.webp',
            'minversion' => '0.0.0',
        ],
    ];
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    Http::fake([
        config('constants.services.official') => Http::response($json, 200, ['Content-Type' => 'application/json']),
    ]);

    $path = service_templates_path();
    $original = File::exists($path) ? File::get($path) : null;

    try {
        config(['app.env' => 'production']);

        (new PullTemplatesFromCDN)->handle();

        $bundle = Cache::get(service_templates_cache_key());

        expect($bundle)
            ->toBeArray()
            ->and($bundle)->toHaveKeys(['fetched_at', 'json'])
            ->and($bundle['json'])->toBe($json)
            ->and(File::get($path))->toBe($json)
            ->and(get_service_templates()->has('buzz'))->toBeTrue();
    } finally {
        if ($original === null) {
            if (File::exists($path)) {
                File::delete($path);
            }
        } else {
            File::put($path, $original);
        }
        Cache::forget(service_templates_cache_key());
    }
});

it('serves templates from shared cache when the local file is stale', function () {
    $stalePath = service_templates_path();
    $original = File::exists($stalePath) ? File::get($stalePath) : null;

    $stale = json_encode(['oldservice' => ['category' => 'other', 'compose' => '']], JSON_THROW_ON_ERROR);
    $fresh = json_encode([
        'buzz' => ['category' => 'messaging', 'compose' => '', 'slogan' => 'Buzz'],
        'newservice' => ['category' => 'other', 'compose' => ''],
    ], JSON_THROW_ON_ERROR);

    try {
        File::put($stalePath, $stale);

        Cache::forever(service_templates_cache_key(), [
            'fetched_at' => now()->toIso8601String(),
            'json' => $fresh,
        ]);

        $templates = get_service_templates();

        expect($templates->has('buzz'))->toBeTrue()
            ->and($templates->has('newservice'))->toBeTrue()
            ->and($templates->has('oldservice'))->toBeFalse();
    } finally {
        if ($original === null) {
            if (File::exists($stalePath)) {
                File::delete($stalePath);
            }
        } else {
            File::put($stalePath, $original);
        }
        Cache::forget(service_templates_cache_key());
    }
});

it('falls back to the local file when shared cache is empty', function () {
    Cache::forget(service_templates_cache_key());

    $templates = get_service_templates();

    expect($templates)->not->toBeEmpty();
});

it('skips pulling templates in local development', function () {
    Http::fake();
    config(['app.env' => 'local']);

    (new PullTemplatesFromCDN)->handle();

    Http::assertNothingSent();
    expect(Cache::get(service_templates_cache_key()))->toBeNull();
});

it('logs when the CDN responds with a non-success status', function () {
    Http::fake([
        'cdn.coollabs.io/*' => Http::response('nope', 503),
    ]);
    config(['app.env' => 'production']);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context = []) => $message === 'PullTemplatesFromCDN failed'
            && data_get($context, 'status') === 503);

    (new PullTemplatesFromCDN)->handle();

    expect(Cache::get(service_templates_cache_key()))->toBeNull();
});
