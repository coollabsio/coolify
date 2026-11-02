<?php

namespace App\Http\Middleware;

use App\Services\Access\AccessResolver;
use Illuminate\Auth\AuthenticationException;
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
    ];

    public const SCOPED_ACCESS_DENIED_MESSAGE = 'This API token is limited to selected projects, environments, or resources, so it cannot use this endpoint.';

    public const TEAM_LEVEL_ADMIN_MESSAGE = 'Only team owners and admins can change team-level settings and infrastructure.';

    public function handle($request, $next, ...$abilities)
    {
        try {
            $user = $request->user();
            $teamId = data_get($user?->currentAccessToken(), 'team_id');

            $isTeamLevelRoute = $request->is('api/v1/*')
                && ! in_array($request->segment(3), self::SCOPED_ACCESS_ROUTES, true)
                && $teamId !== null;

            if ($isTeamLevelRoute && ! app(AccessResolver::class)->coversWholeTeam($user, (int) $teamId)) {
                auditLog('api.auth.scope_denied', [
                    'token_id' => $user->currentAccessToken()?->id,
                    'path' => $request->path(),
                ], 'warning');

                return response()->json(['message' => self::SCOPED_ACCESS_DENIED_MESSAGE], 403);
            }

            // Access grants cover projects and their resources. Team-level routes other than plain reads stay
            // for owners and admins, as in the UI, whatever abilities the token of a member has.
            if ($isTeamLevelRoute && ! in_array('read', $abilities, true) && ! $user->isAdminOfTeam((int) $teamId)) {
                auditLog('api.auth.team_level_denied', [
                    'token_id' => $user->currentAccessToken()?->id,
                    'path' => $request->path(),
                    'required_abilities' => $abilities,
                ], 'warning');

                return response()->json(['message' => self::TEAM_LEVEL_ADMIN_MESSAGE], 403);
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
}
