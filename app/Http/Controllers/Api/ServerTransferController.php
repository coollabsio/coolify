<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\ServerTransfer\ServerTransferBundle;
use App\Services\ServerTransfer\ServerTransferClaimer;
use App\Services\ServerTransfer\ServerTransferExporter;
use App\Services\ServerTransfer\ServerTransferImporter;
use App\Services\ServerTransfer\ServerTransferMigrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Throwable;

class ServerTransferController extends Controller
{
    public function __construct(
        private ServerTransferExporter $exporter,
        private ServerTransferImporter $importer,
        private ServerTransferClaimer $claimer,
        private ServerTransferMigrator $migrator,
    ) {
        abort_unless(isDev(), 404);
    }

    #[OA\Post(
        summary: 'Migrate server to another Coolify instance',
        description: 'One-shot handoff: export this server, import+claim on the target instance (using the provided token), then disable automations here. Requires read:sensitive and write.',
        path: '/servers/{uuid}/migrate',
        operationId: 'migrate-server-between-instances',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['target_url', 'target_token'],
                properties: [
                    new OA\Property(property: 'target_url', type: 'string', example: 'https://coolify-b.example.com'),
                    new OA\Property(property: 'target_token', type: 'string', description: 'API token on the target instance (root or write)'),
                    new OA\Property(property: 'write_remote', type: 'boolean', default: false),
                    new OA\Property(property: 'rebind_sentinel', type: 'boolean', default: true),
                    new OA\Property(property: 'preserve_uuids', type: 'boolean', default: true),
                    new OA\Property(property: 'adopt_mode', type: 'boolean', default: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Migrated'),
            new OA\Response(response: 403, description: 'Missing sensitive permission'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, description: 'Validation or remote import failed'),
        ]
    )]
    public function migrate(Request $request, string $uuid): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if (! $this->canReadSensitive($request)) {
            return response()->json([
                'message' => 'Migrating a server requires a token with read:sensitive (or root) ability and an admin/owner team role.',
            ], 403);
        }

        $server = Server::whereTeamId($teamId)->whereUuid($uuid)->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('update', $server);

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $validator = customApiValidator($request->all(), [
            'target_url' => 'required|string|url',
            'target_token' => 'required|string',
            'write_remote' => 'boolean|nullable',
            'rebind_sentinel' => 'boolean|nullable',
            'preserve_uuids' => 'boolean|nullable',
            'adopt_mode' => 'boolean|nullable',
        ]);
        $allowedFields = ['target_url', 'target_token', 'write_remote', 'rebind_sentinel', 'preserve_uuids', 'adopt_mode'];
        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($validator->fails() || $extraFields !== []) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        try {
            $result = $this->migrator->migrate(
                server: $server,
                targetUrl: $request->string('target_url')->toString(),
                targetToken: $request->string('target_token')->toString(),
                writeRemote: $request->boolean('write_remote', false),
                rebindSentinel: $request->boolean('rebind_sentinel', true),
                preserveUuids: $request->boolean('preserve_uuids', true),
                adoptMode: $request->boolean('adopt_mode', true),
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        auditLog('api.server.migrate', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'export_id' => $result['export_id'],
            'target_url' => $result['target_url'],
        ]);

        return response()->json($result);
    }

    #[OA\Get(
        summary: 'Export server transfer bundle',
        description: 'Export a server and all resources hosted on it as a versioned transfer bundle for moving between Coolify instances. Requires read:sensitive.',
        path: '/servers/{uuid}/export',
        operationId: 'export-server-transfer-bundle',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'encrypt', in: 'query', required: false, description: 'If true and passphrase is provided, return an encrypted envelope.', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'passphrase', in: 'query', required: false, description: 'Passphrase used when encrypt=true.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Transfer bundle'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Missing sensitive permission'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function export(Request $request, string $uuid): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if (! $this->canReadSensitive($request)) {
            return response()->json([
                'message' => 'Exporting a server requires a token with read:sensitive (or root) ability and an admin/owner team role.',
            ], 403);
        }

        $server = Server::whereTeamId($teamId)->whereUuid($uuid)->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('view', $server);

        try {
            $bundle = $this->exporter->export($server, includeSensitive: true);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        auditLog('api.server.export', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'export_id' => data_get($bundle, 'export_id'),
        ]);

        if ($request->boolean('encrypt') && $request->filled('passphrase')) {
            return response()->json(
                ServerTransferBundle::encryptWithPassphrase($bundle, $request->string('passphrase')->toString())
            );
        }

        return response()->json($bundle);
    }

    #[OA\Post(
        summary: 'Import server transfer bundle',
        description: 'Import a server transfer bundle into this Coolify instance (adopt mode by default).',
        path: '/servers/import',
        operationId: 'import-server-transfer-bundle',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'bundle', type: 'object', description: 'Plain or encrypted transfer bundle'),
                    new OA\Property(property: 'passphrase', type: 'string', nullable: true),
                    new OA\Property(property: 'dry_run', type: 'boolean', default: false),
                    new OA\Property(property: 'preserve_uuids', type: 'boolean', default: true),
                    new OA\Property(property: 'adopt_mode', type: 'boolean', default: true, description: 'Import without forcing redeploy; keep statuses for adoption'),
                    new OA\Property(property: 'claim', type: 'boolean', default: true, description: 'Automatically claim the host for this instance after import'),
                    new OA\Property(property: 'write_remote', type: 'boolean', default: false, description: 'When claiming, write ownership file on the host via SSH'),
                    new OA\Property(property: 'rebind_sentinel', type: 'boolean', default: true, description: 'When claiming, rebind Sentinel to this instance'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Dry-run result'),
            new OA\Response(response: 201, description: 'Imported'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function import(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $this->authorize('create', Server::class);

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $validator = customApiValidator($request->all(), [
            'bundle' => 'required|array',
            'passphrase' => 'string|nullable',
            'dry_run' => 'boolean|nullable',
            'preserve_uuids' => 'boolean|nullable',
            'adopt_mode' => 'boolean|nullable',
            'claim' => 'boolean|nullable',
            'write_remote' => 'boolean|nullable',
            'rebind_sentinel' => 'boolean|nullable',
        ]);
        $allowedFields = ['bundle', 'passphrase', 'dry_run', 'preserve_uuids', 'adopt_mode', 'claim', 'write_remote', 'rebind_sentinel'];
        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($validator->fails() || $extraFields !== []) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $bundle = $request->input('bundle', []);
        if (data_get($bundle, 'encrypted')) {
            if (! $request->filled('passphrase')) {
                return response()->json(['message' => 'Passphrase is required for encrypted bundles.'], 422);
            }
            try {
                $bundle = ServerTransferBundle::decryptWithPassphrase($bundle, $request->string('passphrase')->toString());
            } catch (Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        try {
            $result = $this->importer->import(
                bundle: $bundle,
                teamId: $teamId,
                dryRun: $request->boolean('dry_run', false),
                preserveUuids: $request->boolean('preserve_uuids', true),
                adoptMode: $request->boolean('adopt_mode', true),
                claim: $request->boolean('claim', true),
                writeRemote: $request->boolean('write_remote', false),
                rebindSentinel: $request->boolean('rebind_sentinel', true),
            );
        } catch (Throwable $e) {
            $status = $e instanceof ValidationException ? 422 : 422;
            $payload = ['message' => $e->getMessage()];
            if ($e instanceof ValidationException) {
                $payload['errors'] = $e->errors();
            }

            return response()->json($payload, $status);
        }

        auditLog('api.server.import', [
            'team_id' => $teamId,
            'server_uuid' => $result['server_uuid'],
            'export_id' => $result['export_id'],
            'dry_run' => $result['dry_run'],
        ]);

        return response()->json($result, $result['dry_run'] ? 200 : 201);
    }

    #[OA\Post(
        summary: 'Claim imported server',
        description: 'Claim a managed host for this instance: write ownership file and rebind Sentinel.',
        path: '/servers/{uuid}/claim',
        operationId: 'claim-server',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'write_remote', type: 'boolean', default: true),
                    new OA\Property(property: 'rebind_sentinel', type: 'boolean', default: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Claim result'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function claim(Request $request, string $uuid): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $server = Server::whereTeamId($teamId)->whereUuid($uuid)->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('update', $server);

        $validator = customApiValidator($request->all(), [
            'write_remote' => 'boolean|nullable',
            'rebind_sentinel' => 'boolean|nullable',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->claimer->claim(
                $server,
                writeRemote: $request->boolean('write_remote', true),
                rebindSentinel: $request->boolean('rebind_sentinel', true),
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        auditLog('api.server.claim', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'claim_written' => $result['claim_written'],
        ]);

        return response()->json($result);
    }

    #[OA\Post(
        summary: 'Mark server transferred',
        description: 'Source-instance step: disable automations after a successful export/import handoff.',
        path: '/servers/{uuid}/transfer/complete',
        operationId: 'complete-server-transfer',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'export_id', type: 'string', nullable: true),
                    new OA\Property(property: 'target_instance_url', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Marked transferred'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function complete(Request $request, string $uuid): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $server = Server::whereTeamId($teamId)->whereUuid($uuid)->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('update', $server);

        $validator = customApiValidator($request->all(), [
            'export_id' => 'string|nullable',
            'target_instance_url' => 'string|nullable',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->claimer->markTransferred(
                $server,
                exportId: $request->input('export_id'),
                targetInstanceUrl: $request->input('target_instance_url'),
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        auditLog('api.server.transfer_complete', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'export_id' => $request->input('export_id'),
        ]);

        return response()->json($result);
    }

    #[OA\Post(
        summary: 'Write transfer bundle to server mailbox',
        description: 'Write an export bundle to /data/coolify/exports on the managed host for air-gapped import.',
        path: '/servers/{uuid}/export/mailbox',
        operationId: 'export-server-transfer-mailbox',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'passphrase', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Mailbox write result'),
            new OA\Response(response: 403, description: 'Missing sensitive permission'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function writeMailbox(Request $request, string $uuid): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if (! $this->canReadSensitive($request)) {
            return response()->json([
                'message' => 'Writing a transfer mailbox requires read:sensitive (or root) ability and an admin/owner team role.',
            ], 403);
        }

        $server = Server::whereTeamId($teamId)->whereUuid($uuid)->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('view', $server);

        try {
            $bundle = $this->exporter->export($server, includeSensitive: true);
            $result = $this->claimer->writeMailbox(
                $server,
                $bundle,
                $request->filled('passphrase') ? $request->string('passphrase')->toString() : null,
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        auditLog('api.server.export_mailbox', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'export_id' => data_get($bundle, 'export_id'),
            'path' => $result['path'],
        ]);

        return response()->json([
            'export_id' => data_get($bundle, 'export_id'),
            'path' => $result['path'],
            'written' => $result['written'],
            'message' => $result['written']
                ? 'Transfer bundle written to server mailbox.'
                : 'Failed to write mailbox on remote host.',
        ], $result['written'] ? 200 : 422);
    }

    private function canReadSensitive(Request $request): bool
    {
        return (bool) $request->attributes->get('can_read_sensitive', false);
    }
}
