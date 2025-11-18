<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RestoreDatabaseJob;
use App\Models\ScheduledDatabaseBackup;
use App\Models\StandalonePostgresql;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class RestoreDatabaseController extends Controller
{
    #[OA\Post(
        summary: 'Restore Database',
        description: 'Restore a PostgreSQL database from a pgBackRest backup',
        path: '/databases/{uuid}/restore',
        operationId: 'restore-database',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database to restore.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    format: 'uuid',
                )
            ),
        ],
        requestBody: new OA\RequestBody(
            description: 'Restore configuration',
            required: false,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'backup_label' => ['type' => 'string', 'description' => 'Specific backup label to restore from (optional, uses latest if not provided)'],
                        'target_time' => ['type' => 'string', 'description' => 'Point-in-time recovery timestamp (ISO 8601 format, requires PITR enabled)'],
                        'delta' => ['type' => 'boolean', 'description' => 'Use delta restore (only restore changed files)', 'default' => false],
                        'scheduled_backup_uuid' => ['type' => 'string', 'description' => 'UUID of the backup configuration to use'],
                    ],
                ),
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Restore initiated successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Database restore initiated successfully.'),
                        new OA\Property(property: 'restore_job_id', type: 'string', example: '123e4567-e89b-12d3-a456-426614174000'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Bad request - invalid parameters',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                description: 'Database or backup configuration not found',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function restore(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        // Validate incoming request
        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        $validator = customApiValidator($request->all(), [
            'backup_label' => 'string|nullable',
            'target_time' => 'string|nullable',
            'delta' => 'boolean',
            'scheduled_backup_uuid' => 'string|nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (! $request->uuid) {
            return response()->json(['message' => 'Database UUID is required.'], 400);
        }

        $database = queryDatabaseByUuidWithinTeam($request->uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        // Only PostgreSQL databases support pgBackRest restore
        if (! $database instanceof StandalonePostgresql) {
            return response()->json([
                'message' => 'Restore is only supported for PostgreSQL databases.',
            ], 400);
        }

        $this->authorize('update', $database);

        // Find backup configuration
        $backupConfig = null;
        if ($request->filled('scheduled_backup_uuid')) {
            $backupConfig = ScheduledDatabaseBackup::ownedByCurrentTeamAPI($teamId)
                ->where('database_id', $database->id)
                ->where('uuid', $request->scheduled_backup_uuid)
                ->first();

            if (! $backupConfig) {
                return response()->json(['message' => 'Backup configuration not found.'], 404);
            }
        } else {
            // Use the first pgBackRest backup configuration
            $backupConfig = ScheduledDatabaseBackup::ownedByCurrentTeamAPI($teamId)
                ->where('database_id', $database->id)
                ->where('backup_method', 'pgbackrest')
                ->first();

            if (! $backupConfig) {
                return response()->json([
                    'message' => 'No pgBackRest backup configuration found for this database.',
                ], 404);
            }
        }

        // Validate backup method
        if ($backupConfig->backup_method !== 'pgbackrest') {
            return response()->json([
                'message' => 'Restore is only supported for pgBackRest backups.',
            ], 400);
        }

        // Validate PITR requirements
        if ($request->filled('target_time')) {
            if (! $backupConfig->enable_pitr) {
                return response()->json([
                    'message' => 'Point-in-time recovery is not enabled for this backup configuration.',
                    'errors' => ['target_time' => ['PITR must be enabled to restore to a specific time.']],
                ], 422);
            }

            // Validate timestamp format
            try {
                $targetTime = new \DateTime($request->target_time);
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['target_time' => ['Invalid timestamp format. Use ISO 8601 format (e.g., 2025-11-19T10:30:00Z).']],
                ], 422);
            }
        }

        // Dispatch restore job
        $restoreJobId = \Illuminate\Support\Str::uuid()->toString();
        
        try {
            dispatch(new RestoreDatabaseJob(
                database: $database,
                backupConfig: $backupConfig,
                backupLabel: $request->backup_label,
                targetTime: $request->target_time,
                delta: $request->boolean('delta', false),
                jobId: $restoreJobId
            ));

            return response()->json([
                'message' => 'Database restore initiated successfully.',
                'restore_job_id' => $restoreJobId,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to initiate restore.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Get(
        summary: 'List Backups',
        description: 'List all available backups for a pgBackRest-enabled database',
        path: '/databases/{uuid}/backups/list',
        operationId: 'list-database-backups',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    format: 'uuid',
                )
            ),
            new OA\Parameter(
                name: 'scheduled_backup_uuid',
                in: 'query',
                description: 'UUID of the backup configuration (optional)',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    format: 'uuid',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of available backups',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'backups',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'label', type: 'string', example: '20251119-103000F'),
                                    new OA\Property(property: 'type', type: 'string', example: 'full'),
                                    new OA\Property(property: 'timestamp', type: 'object'),
                                    new OA\Property(property: 'database_size', type: 'integer', example: 107374182400),
                                    new OA\Property(property: 'repo_size', type: 'integer', example: 21474836480),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                description: 'Database or backup configuration not found',
            ),
        ]
    )]
    public function list_backups(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if (! $request->uuid) {
            return response()->json(['message' => 'Database UUID is required.'], 400);
        }

        $database = queryDatabaseByUuidWithinTeam($request->uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        if (! $database instanceof StandalonePostgresql) {
            return response()->json([
                'message' => 'Backup listing is only supported for PostgreSQL databases.',
            ], 400);
        }

        $this->authorize('view', $database);

        // Find backup configuration
        $backupConfig = null;
        if ($request->filled('scheduled_backup_uuid')) {
            $backupConfig = ScheduledDatabaseBackup::ownedByCurrentTeamAPI($teamId)
                ->where('database_id', $database->id)
                ->where('uuid', $request->scheduled_backup_uuid)
                ->first();
        } else {
            $backupConfig = ScheduledDatabaseBackup::ownedByCurrentTeamAPI($teamId)
                ->where('database_id', $database->id)
                ->where('backup_method', 'pgbackrest')
                ->first();
        }

        if (! $backupConfig) {
            return response()->json([
                'message' => 'No pgBackRest backup configuration found.',
            ], 404);
        }

        if ($backupConfig->backup_method !== 'pgbackrest') {
            return response()->json([
                'message' => 'Backup listing is only supported for pgBackRest backups.',
            ], 400);
        }

        try {
            $server = $database->destination->server;
            $pgBackRestService = new \App\Services\PgBackRestService($database, $backupConfig, $server);
            $backups = $pgBackRestService->listBackups();

            return response()->json([
                'backups' => $backups,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to list backups.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
