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
    private function outsideHostPathForbiddenResponse(Request $request, LocalFileVolume $storage): ?JsonResponse
    {
        if ($storage->is_host_file || ! $storage->isOutsideResourceDirectory()) {
            return null;
        }
        if ($request->user()->tokenCan('deploy') || $request->user()->tokenCan('root')) {
            return null;
        }

        return response()->json([
            'message' => 'Missing required permissions: deploy. A mount outside the resource directory needs a token with the deploy permission.',
        ], 403);
    }

    private function outsideContentChangeForbiddenResponse(Request $request, LocalFileVolume|LocalPersistentVolume $storage): ?JsonResponse
    {
        if (! $storage instanceof LocalFileVolume || $storage->is_directory || ! $request->has('content')) {
            return null;
        }
        if ((string) $request->input('content') === (string) $storage->content) {
            return null;
        }

        return $this->outsideHostPathForbiddenResponse($request, $storage);
    }
}
