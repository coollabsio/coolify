<?php

namespace App\Actions\Service;

use App\Models\ServiceApplication;
use App\Support\ServiceComposeUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpdateServiceApplicationFromApi
{
    public function execute(ServiceApplication $serviceApplication, Request $request, string $teamId, array $payload): ?JsonResponse
    {
        $forceDomainOverride = $request->boolean('force_domain_override');

        if (array_key_exists('url', $payload)) {
            $urlRaw = $payload['url'];
            if ($urlRaw !== null && ! is_string($urlRaw)) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['url' => 'The url must be a string.'],
                ], 422);
            }

            $parsed = ServiceComposeUrl::validateUrlString(
                is_string($urlRaw) ? $urlRaw : null,
                $forceDomainOverride
            );

            if (count($parsed['errors']) > 0) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $parsed['errors'],
                ], 422);
            }

            if ($parsed['normalized'] !== null) {
                $containerUrls = str($parsed['normalized'])
                    ->explode(',')
                    ->map(fn ($url) => str(trim((string) $url))->lower());

                $result = checkIfDomainIsAlreadyUsedViaAPI($containerUrls, $teamId, $serviceApplication->uuid);
                if (isset($result['error'])) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => [$result['error']],
                    ], 422);
                }

                if ($result['hasConflicts'] && ! $forceDomainOverride) {
                    return response()->json([
                        'message' => 'Domain conflicts detected. Use force_domain_override=true to proceed.',
                        'conflicts' => $result['conflicts'],
                        'warning' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.',
                    ], 409);
                }
            }

            $serviceApplication->fqdn = $parsed['normalized'];
        }

        if (array_key_exists('human_name', $payload)) {
            $serviceApplication->human_name = $payload['human_name'];
        }

        if (array_key_exists('description', $payload)) {
            $serviceApplication->description = $payload['description'];
        }

        if (array_key_exists('image', $payload)) {
            $serviceApplication->image = $payload['image'];
        }

        if (array_key_exists('exclude_from_status', $payload)) {
            $serviceApplication->exclude_from_status = filter_var($payload['exclude_from_status'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('is_gzip_enabled', $payload)) {
            $serviceApplication->is_gzip_enabled = filter_var($payload['is_gzip_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('is_stripprefix_enabled', $payload)) {
            $serviceApplication->is_stripprefix_enabled = filter_var($payload['is_stripprefix_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('is_log_drain_enabled', $payload)) {
            $enabled = filter_var($payload['is_log_drain_enabled'], FILTER_VALIDATE_BOOLEAN);
            $server = $serviceApplication->service->destination->server;
            if ($enabled && ! $server->isLogDrainEnabled()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'is_log_drain_enabled' => ['Log drain is not enabled on the server for this service.'],
                    ],
                ], 422);
            }
            $serviceApplication->is_log_drain_enabled = $enabled;
        }

        $serviceApplication->save();
        $serviceApplication->refresh();

        updateCompose($serviceApplication);

        return null;
    }
}
