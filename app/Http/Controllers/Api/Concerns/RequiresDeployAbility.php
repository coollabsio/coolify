<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The `write` permission changes what runs on a server, and the `deploy` permission makes it run. A
 * change that Coolify runs on a server without a deployment needs both, so it needs a token with the
 * deploy permission (or root).
 */
trait RequiresDeployAbility
{
    /**
     * @param  string  $reason  One sentence that tells why the change needs the deploy permission.
     */
    private function deployAbilityForbiddenResponse(Request $request, string $reason): ?JsonResponse
    {
        if ($request->user()->tokenCan('deploy') || $request->user()->tokenCan('root')) {
            return null;
        }

        return response()->json([
            'message' => "Missing required permissions: deploy. {$reason}",
        ], 403);
    }
}
