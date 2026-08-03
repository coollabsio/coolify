<?php

use App\Http\Middleware\AddServerTimingHeaders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

test('adds Server-Timing and debug headers when server_timing is enabled', function () {
    Config::set('app.server_timing', true);

    $middleware = new AddServerTimingHeaders;
    $request = Request::create('/__server-timing-probe', 'GET');

    $response = $middleware->handle($request, function () {
        DB::select('select 1');

        return response('ok', 200);
    });

    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->has('Server-Timing'))->toBeTrue();

    $serverTiming = $response->headers->get('Server-Timing');
    expect($serverTiming)
        ->toContain('app;desc="Total";dur=')
        ->toContain('db;desc="Database (')
        ->toContain('php;desc="PHP (excl. DB)";dur=')
        ->toContain('dbslow;desc="Slowest query";dur=')
        ->toContain('queries;desc="Query count";dur=')
        ->toContain('html;desc="Response bytes";dur=')
        ->toContain('mem;desc="Peak memory (MB)";dur=');

    expect($response->headers->has('X-Debug-Query-Count'))->toBeTrue();
    expect((int) $response->headers->get('X-Debug-Query-Count'))->toBeGreaterThanOrEqual(1);
    expect($serverTiming)->toMatch('/queries;desc="Query count";dur=\d+/');

    expect($response->headers->has('X-Debug-Memory-MB'))->toBeTrue();
    expect((float) $response->headers->get('X-Debug-Memory-MB'))->toBeGreaterThan(0);

    expect($response->headers->has('X-Debug-Html-Bytes'))->toBeTrue();
    expect((int) $response->headers->get('X-Debug-Html-Bytes'))->toBeGreaterThan(0);
    expect($serverTiming)->toMatch('/html;desc="Response bytes";dur=\d+/');
});

test('injects on-screen HUD into full HTML documents when server_timing is enabled', function () {
    Config::set('app.server_timing', true);

    $middleware = new AddServerTimingHeaders;
    $request = Request::create('/projects', 'GET');

    $response = $middleware->handle($request, function () {
        DB::select('select 1');

        return response('<!DOCTYPE html><html><body><main>projects</main></body></html>', 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    });

    $body = $response->getContent();
    expect($body)
        ->toContain('id="server-timing-hud"')
        ->toContain('data-metrics=')
        ->toContain('data-sth-log')
        ->toContain('data-sth-clear')
        ->toContain('data-sth-toggle')
        ->toContain('Server Timing')
        ->toContain('toggleOpen')
        ->toContain('formatEntryForAi')
        ->toContain('copyEntryById')
        ->toContain('__coolifyServerTimingHistory')
        ->toContain('</body>');
    expect(substr_count($body, 'id="server-timing-hud"'))->toBe(1);
});

test('does not inject HUD into non-HTML or fragment responses', function () {
    Config::set('app.server_timing', true);

    $middleware = new AddServerTimingHeaders;
    $request = Request::create('/api/probe', 'GET');

    $json = $middleware->handle($request, function () {
        return response()->json(['ok' => true]);
    });
    expect($json->headers->has('Server-Timing'))->toBeTrue();
    expect($json->getContent())->not->toContain('server-timing-hud');

    $fragment = $middleware->handle($request, function () {
        return response('<div wire:id="x">partial</div>', 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    });
    expect($fragment->getContent())->not->toContain('server-timing-hud');
});

test('does not add Server-Timing headers when server_timing is disabled', function () {
    Config::set('app.server_timing', false);

    $middleware = new AddServerTimingHeaders;
    $request = Request::create('/__server-timing-probe', 'GET');

    $response = $middleware->handle($request, function () {
        return response('<!DOCTYPE html><html><body>x</body></html>', 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    });

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->headers->has('Server-Timing'))->toBeFalse();
    expect($response->headers->has('X-Debug-Query-Count'))->toBeFalse();
    expect($response->headers->has('X-Debug-Memory-MB'))->toBeFalse();
    expect($response->getContent())->not->toContain('server-timing-hud');
});

test('server_timing defaults off outside the local environment', function () {
    // Mirror phpunit APP_ENV=testing — config is resolved at boot; re-evaluate the default rule.
    expect(config('app.env'))->not->toBe('local');
    // When SERVER_TIMING_ENABLED is unset, non-local envs must not enable headers by default.
    // (phpunit does not set SERVER_TIMING_ENABLED; APP_ENV=testing)
    expect(config('app.server_timing'))->toBeFalse();
});

test('SERVER_TIMING_ENABLED can force server_timing on or off regardless of APP_ENV', function () {
    // Mirrors config/app.php resolution for app.server_timing.
    $resolve = function (?string $enabled, string $appEnv): bool {
        return $enabled !== null
            ? (bool) filter_var($enabled, FILTER_VALIDATE_BOOLEAN)
            : $appEnv === 'local';
    };

    expect($resolve(null, 'local'))->toBeTrue();
    expect($resolve(null, 'production'))->toBeFalse();
    expect($resolve(null, 'testing'))->toBeFalse();

    // Force on in production / staging.
    expect($resolve('true', 'production'))->toBeTrue();
    expect($resolve('1', 'production'))->toBeTrue();
    expect($resolve('yes', 'staging'))->toBeTrue();

    // Force off even in local.
    expect($resolve('false', 'local'))->toBeFalse();
    expect($resolve('0', 'local'))->toBeFalse();
    expect($resolve('off', 'local'))->toBeFalse();
});
