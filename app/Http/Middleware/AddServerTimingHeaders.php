<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds W3C Server-Timing headers in local/dev so Chrome DevTools can show
 * app + database cost per response (Network → Timing → Server Timing).
 *
 * Also injects a small on-screen HUD into full HTML documents so metrics are
 * visible without opening DevTools. Livewire/fetch responses only get headers;
 * the HUD updates from Server-Timing on those requests via a fetch patch.
 *
 * Client-side metrics (paint, LCP, layout, JS/CSS download) cannot be measured
 * here — use the Performance panel. Compare app dur vs wall-clock TTFB to see
 * network/proxy overhead outside PHP.
 */
class AddServerTimingHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldAddHeaders()) {
            return $next($request);
        }

        $startedAt = hrtime(true);
        $queryCount = 0;
        $queryTimeMs = 0.0;
        $slowestQueryMs = 0.0;
        $active = true;

        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queryCount, &$queryTimeMs, &$slowestQueryMs, &$active): void {
            if (! $active) {
                return;
            }

            $queryCount++;
            $queryTimeMs += $query->time;
            if ($query->time > $slowestQueryMs) {
                $slowestQueryMs = $query->time;
            }
        });

        $response = $next($request);
        $active = false;

        return $this->withServerTiming($response, $request, $startedAt, $queryCount, $queryTimeMs, $slowestQueryMs);
    }

    protected function shouldAddHeaders(): bool
    {
        return (bool) config('app.server_timing', false);
    }

    protected function withServerTiming(
        Response $response,
        Request $request,
        int $startedAt,
        int $queryCount,
        float $queryTimeMs,
        float $slowestQueryMs,
    ): Response {
        $totalMs = (hrtime(true) - $startedAt) / 1_000_000;
        $memoryMb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        $content = $response->getContent();
        $htmlBytes = is_string($content) ? strlen($content) : 0;

        $metrics = [
            'app' => round($totalMs, 2),
            'db' => round($queryTimeMs, 2),
            'php' => round(max(0, $totalMs - $queryTimeMs), 2),
            'dbslow' => round($slowestQueryMs, 2),
            'queries' => $queryCount,
            'html' => $htmlBytes,
            'mem' => $memoryMb,
        ];

        // Non-time metrics use dur so Chrome DevTools lists the value in Server Timing.
        // queries = count, html = response body bytes (not milliseconds).
        $headerMetrics = [
            sprintf('app;desc="Total";dur=%.2f', $metrics['app']),
            sprintf('db;desc="Database (%d queries)";dur=%.2f', $queryCount, $metrics['db']),
            sprintf('php;desc="PHP (excl. DB)";dur=%.2f', $metrics['php']),
            sprintf('dbslow;desc="Slowest query";dur=%.2f', $metrics['dbslow']),
            sprintf('queries;desc="Query count";dur=%d', $queryCount),
            sprintf('html;desc="Response bytes";dur=%d', $htmlBytes),
            sprintf('mem;desc="Peak memory (MB)";dur=%.2f', $memoryMb),
        ];

        $response->headers->set('Server-Timing', implode(', ', $headerMetrics));
        // Convenience mirrors for curl / non-DevTools clients.
        $response->headers->set('X-Debug-Memory-MB', (string) $memoryMb);
        $response->headers->set('X-Debug-Query-Count', (string) $queryCount);
        $response->headers->set('X-Debug-Html-Bytes', (string) $htmlBytes);

        return $this->injectHud($response, $request, $metrics);
    }

    /**
     * Inject a floating HUD into full HTML documents only (not Livewire partials/JSON).
     */
    protected function injectHud(Response $response, Request $request, array $metrics): Response
    {
        $content = $response->getContent();
        if (! is_string($content) || $content === '') {
            return $response;
        }

        if (! $this->isFullHtmlDocument($response, $content)) {
            return $response;
        }

        // Avoid double-inject (e.g. nested error pages).
        if (str_contains($content, 'id="server-timing-hud"') || str_contains($content, "id='server-timing-hud'")) {
            return $response;
        }

        $hud = view('components.server-timing-hud', [
            'metrics' => $metrics,
            'path' => '/'.ltrim($request->path(), '/'),
        ])->render();

        $replaced = preg_replace('/<\/body>/i', $hud.'</body>', $content, 1, $count);
        if ($count === 0 || ! is_string($replaced)) {
            return $response;
        }

        $response->setContent($replaced);
        $response->headers->remove('Content-Length');

        // Keep html metric as pre-HUD page size (more useful for profiling the app).

        return $response;
    }

    protected function isFullHtmlDocument(Response $response, string $content): bool
    {
        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && ! str_contains(strtolower($contentType), 'text/html')) {
            return false;
        }

        // Full documents only — skip Livewire component HTML fragments.
        return str_contains(strtolower($content), '</body>')
            && (str_contains(strtolower($content), '<html') || str_contains(strtolower($content), '<!doctype'));
    }
}
