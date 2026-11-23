<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

class ApiAbility extends CheckForAnyAbility
{
    /**
     * First path segment after /api/v1/ of the routes that work on projects and their resources.
     * A token or member limited to some projects, environments, or resources can use only these.
     * Everything else (servers, keys, git sources, S3, cloud providers, team, notifications, settings, ...) is team-level.
     */
    public const SCOPED_ACCESS_ROUTES = [
        'applications',
        'services',
        'databases',
        'projects',
        'deploy',
        'deployments',
        'resources',
        'version',
        'vulnerabilities',
    ];

    /**
     * Path segments after /api/v1/servers/{uuid}/ that a token with a server grant can use on that server.
     * Create, delete, transfer, shared variables, and registry credentials still need the whole team.
     */
    public const SERVER_SCOPE_SECTIONS = [
        'domains',
        'resources',
        'metrics',
        'docker-cleanup',
        'log-drains',
        'sentinel',
        'image-scanning',
        'cloudflare-tunnel',
        'proxy',
        'validate',
        'host-key',
        'destinations',
    ];

    public const SCOPED_ACCESS_DENIED_MESSAGE = 'This API token is limited to selected projects, environments, resources, or servers, so it cannot use this endpoint.';

    public function handle($request, $next, ...$abilities)
    {
        try {
            $user = $request->user();
            $teamId = data_get($user?->currentAccessToken(), 'team_id');

            if ($request->is('api/v1/*')
                && ! in_array($request->segment(3), self::SCOPED_ACCESS_ROUTES, true)
                && $teamId !== null
                && ! app(AccessResolver::class)->coversWholeTeam($user, (int) $teamId)
                && ! $this->allowedByServerGrant($request, $user, (int) $teamId)) {
                auditLog('api.auth.scope_denied', [
                    'token_id' => $user->currentAccessToken()?->id,
                    'path' => $request->path(),
                ], 'warning');

                return response()->json(['message' => self::SCOPED_ACCESS_DENIED_MESSAGE], 403);
            }

            if ($user?->tokenCan('root')) {
                return $next($request);
            }

            return parent::handle($request, $next, ...$abilities);
        } catch (AuthenticationException $e) {
            auditLog('api.auth.unauthenticated', [
                'reason' => $e->getMessage(),
                'required_abilities' => $abilities,
            ], 'warning');

            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        } catch (\Exception $e) {
            auditLog('api.auth.ability_denied', [
                'required_abilities' => $abilities,
                'token_id' => $request->user()?->currentAccessToken()?->id,
                'reason' => $e->getMessage(),
            ], 'warning');

            return response()->json([
                'message' => 'Missing required permissions: '.implode(', ', $abilities),
            ], 403);
        }
    }

    /**
     * A token with server grants can list its servers (the list is filtered) and use the
     * SERVER_SCOPE_SECTIONS endpoints, GET, and PATCH of those servers.
     */
    private function allowedByServerGrant(Request $request, User $user, int $teamId): bool
    {
        if ($request->segment(3) !== 'servers') {
            return false;
        }

        $serverUuids = app(AccessResolver::class)->scopedServerUuids($user, $teamId) ?? [];
        $serverUuid = $request->segment(4);
        if ($serverUuid === null) {
            return $request->isMethod('GET') && $serverUuids !== [];
        }

        $section = $request->segment(5);
        $allowedSection = $section === null
            ? $request->isMethod('GET') || $request->isMethod('PATCH')
            : in_array($section, self::SERVER_SCOPE_SECTIONS, true);

        return $allowedSection && in_array($serverUuid, $serverUuids, true);
    }
}
