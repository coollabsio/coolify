<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\Server;
use App\Rules\SafeWebhookUrl;
use App\Rules\ValidServerIp;
use App\Services\ServerTransfer\ServerTransferBundle;
use App\Services\ServerTransfer\ServerTransferClaimer;
use App\Services\ServerTransfer\ServerTransferImporter;
use App\Services\ServerTransfer\ServerTransferMigrator;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Throwable;

class ServerTransferController extends Controller
{
    public function __construct(
        private ServerTransferImporter $importer,
        private ServerTransferClaimer $claimer,
        private ServerTransferMigrator $migrator,
    ) {}

    #[OA\Post(
        summary: 'Migrate server to another Coolify instance',
        description: 'Start a transfer in the background: export this server, import and claim it on the target instance (with the given token), then disable automations here. All secrets of the server, also locked environment variables, are sent to the target. Follow the progress with GET /servers/{uuid}/transfer. The target URL must use https. Requires read:sensitive and write, and the owner role of the server team.',
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
                ]
            )
        ),
        responses: [
            new OA\Response(response: 202, description: 'Transfer started'),
            new OA\Response(response: 403, description: 'Missing sensitive permission or not an owner of the server team'),
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
                'message' => 'Migrating a server requires a token with read:sensitive (or root) ability and the owner team role.',
            ], 403);
        }

        $server = Server::whereTeamId($teamId)->whereUuid($uuid)->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('transfer', $server);

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $validator = customApiValidator($request->all(), [
            'target_url' => 'required|string|url',
            'target_token' => 'required|string',
        ]);
        $allowedFields = ['target_url', 'target_token'];
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
            $this->migrator->start(
                server: $server,
                targetUrl: $request->string('target_url')->toString(),
                targetToken: $request->string('target_token')->toString(),
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $server->refresh();
        auditLog('api.server.migrate', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'target_url' => data_get($server->server_metadata, 'migration.target_url'),
        ]);

        return response()->json([
            'server_uuid' => $server->uuid,
            'migration' => data_get($server->server_metadata, 'migration'),
            'message' => 'Transfer started. Follow it with GET /api/v1/servers/'.$server->uuid.'/transfer.',
        ], 202);
    }

    #[OA\Get(
        summary: 'Get server transfer status',
        description: 'Show the transfer state of a server on this instance: the import/claim state (transfer) and the state of a running or finished transfer to another instance (migration).',
        path: '/servers/{uuid}/transfer',
        operationId: 'get-server-transfer-status',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Transfer status'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function status(Request $request, string $uuid): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $server = Server::whereTeamId($teamId)->whereUuid($uuid)->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('view', $server);

        // The log and the error of a transfer can name hosts and credentials problems, so members see only the state.
        $migration = data_get($server->server_metadata, 'migration');
        if (is_array($migration) && ! $request->user()->can('update', $server)) {
            $migration = collect($migration)->except(['log', 'error'])->all();
        }

        return response()->json([
            'server_uuid' => $server->uuid,
            'transfer' => collect((array) data_get($server->server_metadata, 'transfer', []))
                ->only(['status', 'export_id', 'imported_at', 'claimed_at', 'transferred_at', 'target_instance_url'])
                ->all(),
            'migration' => $migration,
        ]);
    }

    #[OA\Get(
        summary: 'Check server import',
        description: 'Check that this instance can import a server, before the source instance starts the transfer. It checks the token, the bundle version, the server limit, servers with the same UUID or IP, and that this instance can reach the SSH port of the server.',
        path: '/servers/import/check',
        operationId: 'check-server-import',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'server_uuid', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'ip', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'port', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 22)),
            new OA\Parameter(name: 'schema_version', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'system_github_apps[]', in: 'query', required: false, description: 'UUIDs of system-wide GitHub Apps that the server uses. The response lists the ones this instance does not have.', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))),
            new OA\Parameter(name: 'system_gitlab_apps[]', in: 'query', required: false, description: 'UUIDs of system-wide GitLab Apps that the server uses. The response lists the ones this instance does not have.', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The server can be imported. Lists every check with its result.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 422, description: 'One or more checks failed. Lists every check with its result.'),
        ]
    )]
    public function check(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $this->authorize('create', Server::class);

        $validator = customApiValidator($request->query(), [
            'server_uuid' => 'required|string',
            'ip' => ['required', 'string', new ValidServerIp],
            'port' => 'nullable|integer|between:1,65535',
            'schema_version' => 'nullable|integer',
            'system_github_apps' => 'nullable|array|max:100',
            'system_github_apps.*' => 'string|max:64',
            'system_gitlab_apps' => 'nullable|array|max:100',
            'system_gitlab_apps.*' => 'string|max:64',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // System-wide git sources are not transferred. Applications re-link only to one with the same uuid here.
        $missingSystemSources = [
            'github' => array_values(array_diff((array) $request->query('system_github_apps', []), GithubApp::query()->where('is_system_wide', true)->whereIn('uuid', (array) $request->query('system_github_apps', []))->pluck('uuid')->all())),
            'gitlab' => array_values(array_diff((array) $request->query('system_gitlab_apps', []), GitlabApp::query()->where('is_system_wide', true)->whereIn('uuid', (array) $request->query('system_gitlab_apps', []))->pluck('uuid')->all())),
        ];

        $schemaVersion = (int) $request->query('schema_version', ServerTransferBundle::SCHEMA_VERSION);
        $ip = trim((string) $request->query('ip'));
        $port = (int) $request->query('port', 22);

        // The target connects to the server over SSH when it takes over management, so an unreachable
        // server would leave an imported server here that this instance cannot manage.
        // On Cloud the probe is rate limited and cannot reach private, loopback, or link-local addresses,
        // so users cannot scan ports of Cloud's own network.
        // A host name is resolved once and the probe connects to that address, so DNS cannot change it in between.
        Server::throttleValidation($teamId, $ip);
        $probeIp = Server::normalizeIp($ip);
        if (isCloud() && ! filter_var($probeIp, FILTER_VALIDATE_IP)) {
            $probeIp = gethostbyname($probeIp);
        }
        $probeHost = filter_var($probeIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$probeIp}]" : $probeIp;
        $probeAllowed = ! isCloud() || (filter_var($probeIp, FILTER_VALIDATE_IP)
            && Validator::make(['ip' => "http://{$probeHost}:{$port}"], ['ip' => [new SafeWebhookUrl]])->passes());
        $connection = $probeAllowed ? @fsockopen($probeHost, $port, timeout: 5) : false;
        if ($connection !== false) {
            fclose($connection);
        }

        $checks = [
            [
                'label' => 'the transfer bundle version is supported',
                'ok' => $schemaVersion === ServerTransferBundle::SCHEMA_VERSION,
                'message' => $schemaVersion === ServerTransferBundle::SCHEMA_VERSION ? null
                    : 'This instance reads transfer bundle version '.ServerTransferBundle::SCHEMA_VERSION.", but the source sends version {$schemaVersion}. Upgrade both instances to the same Coolify version.",
            ],
            ...collect($this->importer->importChecks((string) $request->query('server_uuid'), $ip, $teamId))
                ->map(fn (array $check) => collect($check)->only(['label', 'ok', 'message'])->all())
                ->all(),
            [
                'label' => "the target can reach the SSH port of the server ({$ip}:{$port})",
                'ok' => $connection !== false,
                'message' => match (true) {
                    $connection !== false => null,
                    ! $probeAllowed => "This instance cannot connect to {$ip}: it is a private, loopback, or link-local address, or it does not resolve to a public IPv4 address. Use the public IP address of the server.",
                    default => "This instance cannot reach the server at {$ip}:{$port}. Allow SSH from this instance to the server, then transfer again.",
                },
            ],
        ];

        $failed = collect($checks)->where('ok', false);

        return response()->json([
            'message' => $failed->isEmpty()
                ? 'This instance can import the server.'
                : $failed->pluck('message')->implode(' '),
            'checks' => $checks,
            'missing_system_sources' => $missingSystemSources,
        ], $failed->isEmpty() ? 200 : 422);
    }

    #[OA\Post(
        summary: 'Import server transfer bundle',
        description: 'Import a server transfer bundle into this Coolify instance (adopt mode by default). This instance then manages the server, and Sentinel reports to it. Needs a token with the write and deploy permissions.',
        path: '/servers/import',
        operationId: 'import-server-transfer-bundle',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'bundle', type: 'object', description: 'Transfer bundle'),
                    new OA\Property(property: 'dry_run', type: 'boolean', default: false),
                    new OA\Property(property: 'preserve_uuids', type: 'boolean', default: true),
                    new OA\Property(property: 'adopt_mode', type: 'boolean', default: true, description: 'Import without forcing redeploy; keep statuses for adoption'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Dry-run result'),
            new OA\Response(response: 201, description: 'Imported'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token needs the write and deploy permissions.'),
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
            'dry_run' => 'boolean|nullable',
            'preserve_uuids' => 'boolean|nullable',
            'adopt_mode' => 'boolean|nullable',
        ]);
        $allowedFields = ['bundle', 'dry_run', 'preserve_uuids', 'adopt_mode'];
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
            $result = $this->importer->import(
                bundle: $request->input('bundle', []),
                teamId: $teamId,
                dryRun: $request->boolean('dry_run', false),
                preserveUuids: $request->boolean('preserve_uuids', true),
                adoptMode: $request->boolean('adopt_mode', true),
            );
        } catch (ThrottleRequestsException $e) {
            throw $e;
        } catch (Throwable $e) {
            $payload = ['message' => $e->getMessage()];
            if ($e instanceof ValidationException) {
                $payload['errors'] = $e->errors();
            }

            return response()->json($payload, 422);
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
        summary: 'Mark server transferred',
        description: 'Source-instance recovery step: disable automations here when the target already manages the server but the transfer could not finish on this instance.',
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

        // The UI prefills this URL for a transfer back, which sends a team token to it.
        $targetInstanceUrl = $request->input('target_instance_url');
        if (filled($targetInstanceUrl)) {
            try {
                $targetInstanceUrl = ServerTransferMigrator::validateInstanceUrl($targetInstanceUrl);
            } catch (ValidationException $e) {
                return response()->json(['message' => 'Validation failed.', 'errors' => ['target_instance_url' => $e->validator->errors()->all()]], 422);
            } catch (Throwable $e) {
                return response()->json(['message' => 'Validation failed.', 'errors' => ['target_instance_url' => [$e->getMessage()]]], 422);
            }
        }

        try {
            $result = $this->claimer->markTransferred(
                $server,
                exportId: $request->input('export_id'),
                targetInstanceUrl: filled($targetInstanceUrl) ? $targetInstanceUrl : null,
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

    private function canReadSensitive(Request $request): bool
    {
        return (bool) $request->attributes->get('can_read_sensitive', false);
    }
}
