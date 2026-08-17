<?php

namespace App\Http\Middleware;

use Illuminate\Auth\AuthenticationException;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

class ApiAbility extends CheckForAnyAbility
{
    /**
     * Permissions that only admins/owners may use.
     */
    private const MEMBER_DISALLOWED_ABILITIES = [
        'root',
        'write',
        'write:sensitive',
        'deploy',
        'read:sensitive',
    ];

    public function handle($request, $next, ...$abilities)
    {
        try {
            $token = $request->user()->currentAccessToken();
            $teamId = data_get($token, 'team_id');

            if ($teamId !== null && ! $request->user()->isAdminOfTeam((int) $teamId)) {
                $tokenAbilities = $token->abilities ?? [];
                $disallowed = array_intersect($tokenAbilities, self::MEMBER_DISALLOWED_ABILITIES);

                if (! empty($disallowed)) {
                    return response()->json([
                        'message' => 'This API token has permissions ('.implode(', ', $disallowed).') that exceed your current role as a team member. Members are restricted to read-only API access. Please revoke this token and create a new one with only read permissions.',
                    ], 403);
                }
            }

            if ($request->user()->tokenCan('root')) {
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
