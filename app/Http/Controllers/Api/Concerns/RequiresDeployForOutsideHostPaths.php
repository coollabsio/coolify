<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Coolify writes mounts outside the resource directory on the host without a deployment, so a token
 * needs the deploy permission (or root) for them.
 */
trait RequiresDeployForOutsideHostPaths
{
    use RequiresDeployAbility;

    private function outsideHostPathForbiddenResponse(Request $request, LocalFileVolume $storage): ?JsonResponse
    {
        if ($storage->is_host_file || ! $storage->isOutsideResourceDirectory()) {
            return null;
        }

        return $this->deployAbilityForbiddenResponse($request, 'A mount outside the resource directory needs a token with the deploy permission.');
    }

    /**
     * Coolify writes changed file content on the server immediately, also inside the resource directory.
     */
    private function contentChangeForbiddenResponse(Request $request, LocalFileVolume|LocalPersistentVolume $storage): ?JsonResponse
    {
        if (! $storage instanceof LocalFileVolume || $storage->is_directory || $storage->is_host_file || ! $request->has('content')) {
            return null;
        }
        if ((string) $request->input('content') === (string) $storage->content) {
            return null;
        }

        return $this->deployAbilityForbiddenResponse($request, 'Coolify writes the file content on the server immediately, so it needs a token with the deploy permission.');
    }
}
