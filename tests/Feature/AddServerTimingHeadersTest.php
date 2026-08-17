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
    // Docks into main top bar via #server-timing-hud-slot; float fallback is bottom-left.
    expect($body)
        ->toContain('dockHud')
        ->toContain('data-sth-mode="float"')
        ->toContain('position:fixed;bottom:12px;left:12px')
        ->not->toContain('position:fixed;bottom:12px;right:12px')
        ->not->toContain('position:fixed;top:56px;right:12px');
    // Inline <script> must not embed literal HTML closers (browser ends the script early).
    $hudScript = null;
    if (preg_match('/id="server-timing-hud".*?<script data-navigate-once>(.*?)<\/script>/s', $body, $m)) {
        $hudScript = $m[1];
    }
    expect($hudScript)->not->toBeNull();
    expect($hudScript)
        ->not->toContain('</body>')
        ->not->toContain('</BODY>')
        ->not->toContain('</script>')
        ->not->toContain('</SCRIPT>');
});

test('removes stale Content-Length after injecting the HUD', function () {
    Config::set('app.server_timing', true);

    $middleware = new AddServerTimingHeaders;
    $request = Request::create('/projects', 'GET');
    $html = '<!DOCTYPE html><html><body><main>projects</main></body></html>';

    $response = $middleware->handle($request, function () use ($html) {
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Length' => (string) strlen($html),
        ]);
    });

    expect($response->getContent())->toContain('id="server-timing-hud"');
    expect($response->headers->has('Content-Length'))->toBeFalse();
});

test('app shell exposes Server-Timing HUD dock slots in desktop and mobile top bars', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)
        ->toContain('id="server-timing-hud-slot"')
        ->toContain('id="server-timing-hud-slot-mobile"')
        ->toContain('data-server-timing-hud-slot');
});

test('Server-Timing HUD docks into navbar slots and floats only as fallback', function () {
    $hud = file_get_contents(resource_path('views/components/server-timing-hud.blade.php'));

    expect($hud)
        ->toContain('server-timing-hud-slot')
        ->toContain('server-timing-hud-slot-mobile')
        ->toContain("matchMedia('(min-width: 1024px)')")
        ->toContain('floats bottom-left only if no navbar slot is available')
        // Both navbar pills stay compact (app ms only); the full breakdown lives in the panel/float fallback.
        ->toContain("root.getAttribute('data-sth-mode') === 'docked'")
        ->toContain('compactSummary')
        ->not->toContain("compactSummary = root.getAttribute('data-sth-mode') === 'docked'\n            && root.parentElement");
});

test('Server-Timing HUD follows the application color mode', function () {
    $hud = file_get_contents(resource_path('views/components/server-timing-hud.blade.php'));

    expect($hud)
        ->toContain('#server-timing-hud {')
        ->toContain('html.dark #server-timing-hud {')
        ->toContain('--sth-background: rgba(255, 255, 255, .96)')
        ->toContain('--sth-background: rgba(16, 16, 16, .96)')
        ->toContain('var(--sth-text)')
        ->toContain('var(--sth-border)')
        ->toContain('[data-sth-log]::-webkit-scrollbar-thumb')
        ->toContain('scrollbar-color: var(--sth-scrollbar-thumb) var(--sth-scrollbar-track)');
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
test('server timing HUD can be toggled from the development command center', function () {
    $hud = file_get_contents(resource_path('views/components/server-timing-hud.blade.php'));
    $commandCenter = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    expect($hud)
        ->toContain('coolify.serverTimingHud.enabled')
        ->toContain('server-timing-hud-visibility-changed')
        ->and($commandCenter)
        ->toContain("app()->environment('local')")
        ->toContain('Toggle Server Timing HUD')
        ->toContain("localStorage.setItem('coolify.serverTimingHud.enabled'")
        ->toContain("window.dispatchEvent(new CustomEvent('server-timing-hud-visibility-changed'))");
});
