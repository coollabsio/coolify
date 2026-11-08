<?php

namespace App\Http\Controllers\Api;

use App\Actions\ImageScan\SetServerImageScanning;
use App\Http\Controllers\Controller;
use App\Jobs\PrepareImageScannerJob;
use App\Models\Server;
use App\Services\ImageScan\ImageScanningRefused;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

class ServerImageScanningController extends Controller
{
    private const ALLOWED_FIELDS = ['is_image_scan_enabled'];

    /**
     * @return array{is_image_scan_enabled: bool, scanner_state: ?string, scanner_failed_step: ?string, scanner_built_at: ?string, scanner_disk_used_bytes: ?int}
     */
    private function transform(Server $server): array
    {
        $state = Cache::get(PrepareImageScannerJob::stateCacheKey($server));
        $failedStep = Cache::get(PrepareImageScannerJob::failedStepCacheKey($server));
        $builtAt = Cache::get(PrepareImageScannerJob::builtAtCacheKey($server));
        $diskUsed = Cache::get(PrepareImageScannerJob::diskUsedCacheKey($server));

        return [
            'is_image_scan_enabled' => (bool) $server->settings->is_image_scan_enabled,
            'scanner_state' => is_string($state) ? $state : null,
            'scanner_failed_step' => is_string($failedStep) ? $failedStep : null,
            'scanner_built_at' => is_string($builtAt) ? CarbonImmutable::parse($builtAt)->toIso8601String() : null,
            'scanner_disk_used_bytes' => is_int($diskUsed) ? $diskUsed : null,
        ];
    }

    private function findServer(Request $request): ?Server
    {
        $teamId = getTeamIdFromToken();

        return is_null($teamId) ? null : Server::whereTeamId($teamId)->whereUuid($request->route('uuid'))->first();
    }

    #[OA\Get(
        summary: 'Get image scanning settings',
        description: 'Get the image vulnerability scanning settings of a server owned by the authenticated team, and the state of the scanner on the server. Needs a team admin or owner.',
        path: '/servers/{uuid}/image-scanning',
        operationId: 'get-server-image-scanning',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Image scanning settings.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'is_image_scan_enabled', type: 'boolean', description: 'Whether the server scans the images of its resources.'),
                        new OA\Property(property: 'scanner_state', type: 'string', nullable: true, enum: ['pulling', 'updating_database', 'ready', 'failed'], description: 'State of the scanner on the server. Null when unknown.'),
                        new OA\Property(property: 'scanner_failed_step', type: 'string', nullable: true, description: 'The step that failed when scanner_state is failed.'),
                        new OA\Property(property: 'scanner_built_at', type: 'string', format: 'date-time', nullable: true, description: 'Time of the vulnerability database on the server.'),
                        new OA\Property(property: 'scanner_disk_used_bytes', type: 'integer', nullable: true, description: 'Disk space that the scanner uses on the server.'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 403, description: 'Needs a team admin or owner.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        if (is_null(getTeamIdFromToken())) {
            return invalidTokenResponse();
        }

        $server = $this->findServer($request);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('viewSecurity', $server);

        return response()->json($this->transform($server));
    }

    #[OA\Patch(
        summary: 'Update image scanning settings',
        description: 'Turn image vulnerability scanning of a server owned by the authenticated team on or off. Turning it on gets the scanner on the server in the background.',
        path: '/servers/{uuid}/image-scanning',
        operationId: 'update-server-image-scanning',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['is_image_scan_enabled'],
                properties: [
                    new OA\Property(property: 'is_image_scan_enabled', type: 'boolean'),
                ],
                type: 'object',
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated image scanning settings.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 403, description: 'Needs a team admin or owner.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'The instance does not allow image scanning, or another Coolify instance manages the server.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function update(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $server = $this->findServer($request);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('update', $server);

        $validator = customApiValidator($request->all(), [
            'is_image_scan_enabled' => 'required|boolean',
        ]);
        $extraFields = array_diff(array_keys($request->all()), self::ALLOWED_FIELDS);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $enabled = $request->boolean('is_image_scan_enabled');
        try {
            SetServerImageScanning::run($server, $enabled);
        } catch (ImageScanningRefused $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        auditLog($enabled ? 'api.server.image_scanning_enabled' : 'api.server.image_scanning_disabled', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
        ]);

        return response()->json($this->transform($server->refresh()));
    }
}
