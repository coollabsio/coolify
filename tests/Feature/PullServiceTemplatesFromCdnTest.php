<?php

use App\Jobs\PullTemplatesFromCDN;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

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

it('keeps the local templates when the CDN returns an unusable bundle', function (string $body) {
    Http::fake([
        config('constants.services.official') => Http::response($body, 200),
    ]);

    $path = service_templates_path();
    $original = File::get($path);

    try {
        config(['app.env' => 'production']);

        (new PullTemplatesFromCDN)->handle();

        expect(md5(File::get($path)))->toBe(md5($original))
            ->and(Cache::get(service_templates_cache_key()))->toBeNull()
            ->and(get_service_templates())->not->toBeEmpty();
    } finally {
        File::put($path, $original);
        Cache::forget(service_templates_cache_key());
    }
})->with([
    'empty body' => '',
    'html error page' => '<html>Bad gateway</html>',
    'empty object' => '{}',
    'json list' => '[1, 2]',
]);

it('logs without notifying when the CDN responds with a non-success status', function () {
    Team::factory()->create(['id' => 0])->discordNotificationSettings()->update(['discord_enabled' => true]);
    Notification::fake();
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
    Notification::assertNothingSent();
});

it('takes the service templates of the channel of the running version', function (string $version, ?string $url) {
    config(['app.env' => 'production', 'constants.coolify.version' => $version]);

    expect(service_templates_cdn_url())->toBe($url);
})->with([
    'stable' => ['4.7.5', 'https://cdn.coollabs.io/coolify/service-templates-latest.json'],
    'beta' => ['4.8.0-beta.1', 'https://cdn.coollabs.io/coolify/preview/service-templates-latest.json'],
    'release candidate' => ['4.8.0-rc.2', 'https://cdn.coollabs.io/coolify/preview/service-templates-latest.json'],
    'alpha' => ['4.8.0-alpha.1', 'https://cdn.coollabs.io/coolify/nightly/service-templates-latest.json'],
    'nightly' => ['4.8.0-alpha.0.20261116.1', 'https://cdn.coollabs.io/coolify/nightly/service-templates-latest.json'],
    'pull request' => ['4.7.5-pr.12345', null],
]);

it('uses the service templates of the image in development', function () {
    config(['app.env' => 'local', 'constants.coolify.version' => '4.7.5']);

    expect(service_templates_cdn_url())->toBeNull();
});

it('pulls the service templates of the nightly channel on a nightly', function () {
    $json = json_encode(['buzz' => ['category' => 'messaging', 'compose' => '', 'slogan' => 'Buzz']], JSON_THROW_ON_ERROR);
    Http::preventStrayRequests();
    Http::fake(['https://cdn.coollabs.io/coolify/nightly/service-templates-latest.json' => Http::response($json)]);
    config(['app.env' => 'production', 'constants.coolify.version' => '4.8.0-alpha.0.20261116.1']);
    $path = service_templates_path();
    $original = File::get($path);

    try {
        (new PullTemplatesFromCDN)->handle();

        expect(get_service_templates()->keys()->all())->toBe(['buzz']);
    } finally {
        File::put($path, $original);
    }
});

it('uses the service templates of the image on a pull request build', function () {
    Http::preventStrayRequests();
    config(['app.env' => 'production']);
    Cache::forever(service_templates_cache_key(), [
        'fetched_at' => now()->toIso8601String(),
        'json' => json_encode(['cdn-only' => ['category' => 'other', 'compose' => '']], JSON_THROW_ON_ERROR),
    ]);
    config(['constants.coolify.version' => '4.7.5-pr.12345']);

    (new PullTemplatesFromCDN)->handle();

    expect(get_service_templates(force: true))
        ->has('cdn-only')->toBeFalse()
        ->has('activepieces')->toBeTrue();
});
