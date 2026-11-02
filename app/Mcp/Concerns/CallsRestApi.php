<?php

namespace App\Mcp\Concerns;

use Illuminate\Http\Request as HttpRequest;
use Illuminate\Routing\Pipeline;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Runs a fixed Coolify REST API route inside the current MCP request.
 *
 * The REST route runs with its own middleware (Sanctum, token team, role, ability,
 * IP allow-list, sensitive-data masking) and its own controller validation and
 * policies, so MCP write tools always have the same security checks as the REST API.
 */
trait CallsRestApi
{
    /**
     * Route prefixes that MCP must never reach, even by mistake.
     *
     * @var array<int, string>
     */
    private array $blockedRestPaths = [
        'api/v1/enable',
        'api/v1/disable',
        'api/v1/mcp/',
        'api/v1/feedback',
        'api/v1/sentinel/',
        'api/v1/settings/',
        'api/v1/teams',
        'api/v1/security/integration-tokens',
    ];

    /**
     * Response keys that are never returned to MCP clients, whatever the token abilities are.
     */
    private string $neverReturnKeyPattern = '/(private_key|secret|api_key|license_key|user_key|webhook_url|password|^(internal|external)_db_url$|(^|_)token$)/i';

    private const MAX_BODY_BYTES = 1_048_576;

    /**
     * @param  array<string, string|int>  $pathParams  Values for {placeholders} in $path.
     * @param  array<string, mixed>  $body
     * @return array{status: int, body: mixed}
     */
    protected function callRestApi(string $method, string $path, array $pathParams = [], array $body = []): array
    {
        $resolvedPath = preg_replace_callback('/\{(\w+)\}/', function (array $match) use ($pathParams): string {
            $value = $pathParams[$match[1]] ?? null;
            if (! $this->isSafePathSegment($value)) {
                throw new \InvalidArgumentException("{$match[1]} is missing or has invalid characters.");
            }

            return rawurlencode((string) $value);
        }, $path);

        $uri = '/api/v1/'.ltrim($resolvedPath, '/');
        foreach ($this->blockedRestPaths as $blocked) {
            if (str_starts_with(ltrim($uri, '/'), $blocked)) {
                throw new \InvalidArgumentException('This API route is not available through MCP.');
            }
        }

        $json = json_encode((object) $body, JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_BODY_BYTES) {
            throw new \InvalidArgumentException('Request data is too large.');
        }

        // REST controllers read GET filters and DELETE flags (?force=...) from the query string.
        $usesQueryString = in_array($method, ['GET', 'DELETE'], true);

        /** @var HttpRequest $original */
        $original = app('request');
        if ($usesQueryString && $body !== []) {
            $uri .= '?'.http_build_query($body);
        }

        $subRequest = HttpRequest::create(
            $uri,
            $method,
            $usesQueryString ? [] : $body,
            [],
            [],
            $original->server->all(),
            $usesQueryString ? null : $json,
        );
        if (! $usesQueryString) {
            // Same input as a real JSON request: $request->get()/all() read the JSON body.
            $subRequest->setJson(new InputBag($body));
            $subRequest->request = $subRequest->json();
        }
        $subRequest->headers->set('Accept', 'application/json');
        $subRequest->headers->set('Content-Type', 'application/json');
        // Server-side marker only: request attributes cannot be set by the client.
        $subRequest->attributes->set('mcp_rest_bridge', true);
        $subRequest->setUserResolver($original->getUserResolver());

        /** @var Router $router */
        $router = app('router');

        app()->instance('request', $subRequest);
        Facade::clearResolvedInstance('request');

        try {
            $route = $router->getRoutes()->match($subRequest);
            $subRequest->setRouteResolver(fn () => $route);

            $response = (new Pipeline(app()))
                ->send($subRequest)
                ->through($router->gatherRouteMiddleware($route))
                ->then(fn ($request) => $router->prepareResponse($request, $route->run()));
        } catch (HttpExceptionInterface $e) {
            return ['status' => $e->getStatusCode(), 'body' => ['message' => $e->getMessage() ?: 'Not found.']];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 500, 'body' => ['message' => 'Something went wrong while processing the request.']];
        } finally {
            app()->instance('request', $original);
            Facade::clearResolvedInstance('request');
        }

        $decoded = json_decode((string) $response->getContent(), true);

        if (is_array($decoded)) {
            $decoded = $this->removeNeverReturnedKeys($decoded);
            // Some REST write responses include credentials (for example database URLs) for write-only tokens.
            if ($subRequest->attributes->get('can_read_sensitive') !== true) {
                // REST management actions use the record id for follow-up updates and deletes.
                // Keep foreign IDs and credentials filtered; read tools still omit record IDs.
                $decoded = $this->scrubSensitive($decoded, ['id']);
            }
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => is_array($decoded) ? $decoded : ['message' => (string) $response->getContent()],
        ];
    }

    private function isSafePathSegment(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0;
        }

        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\-]{0,254}$/', $value) === 1 && ! str_contains($value, '..');
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function removeNeverReturnedKeys(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match($this->neverReturnKeyPattern, $key) === 1) {
                continue;
            }
            $out[$key] = is_array($value) ? $this->removeNeverReturnedKeys($value) : $value;
        }

        return $out;
    }
}
