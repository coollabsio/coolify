<?php

namespace App\Http\Controllers\Api;

use App\Actions\Server\ValidateServer;
use App\Enums\ProxyTypes;
use App\Exceptions\RateLimitException;
use App\Http\Controllers\Controller;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Rules\ValidHostname;
use App\Services\HostingerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class HostingerController extends Controller
{
    #[OA\Get(
        path: '/hostinger/data-centers',
        operationId: 'get-hostinger-data-centers',
        summary: 'Get Hostinger VPS data centers',
        security: [['bearerAuth' => []]],
        tags: ['Hostinger'],
        responses: [
            new OA\Response(response: 200, description: 'List of Hostinger VPS data centers.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ]
    )]
    public function dataCenters(Request $request): JsonResponse
    {
        return $this->providerData($request, 'getDataCenters', 'data centers');
    }

    #[OA\Get(
        path: '/hostinger/catalog',
        operationId: 'get-hostinger-catalog',
        summary: 'Get Hostinger VPS plans and prices',
        security: [['bearerAuth' => []]],
        tags: ['Hostinger'],
        responses: [
            new OA\Response(response: 200, description: 'List of Hostinger VPS plans and prices.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ]
    )]
    public function catalog(Request $request): JsonResponse
    {
        return $this->providerData($request, 'getCatalogItems', 'catalog');
    }

    #[OA\Get(
        path: '/hostinger/templates',
        operationId: 'get-hostinger-templates',
        summary: 'Get Hostinger VPS operating system templates',
        security: [['bearerAuth' => []]],
        tags: ['Hostinger'],
        responses: [
            new OA\Response(response: 200, description: 'List of Hostinger VPS templates.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ]
    )]
    public function templates(Request $request): JsonResponse
    {
        return $this->providerData($request, 'getTemplates', 'templates');
    }

    #[OA\Get(
        path: '/hostinger/ssh-keys',
        operationId: 'get-hostinger-ssh-keys',
        summary: 'Get Hostinger account SSH keys',
        security: [['bearerAuth' => []]],
        tags: ['Hostinger'],
        responses: [
            new OA\Response(response: 200, description: 'List of Hostinger account SSH keys.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ]
    )]
    public function sshKeys(Request $request): JsonResponse
    {
        return $this->providerData($request, 'getPublicKeys', 'SSH keys');
    }

    #[OA\Get(
        path: '/hostinger/post-install-scripts',
        operationId: 'get-hostinger-post-install-scripts',
        summary: 'Get Hostinger post-install scripts',
        security: [['bearerAuth' => []]],
        tags: ['Hostinger'],
        responses: [
            new OA\Response(response: 200, description: 'List of Hostinger post-install scripts.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ]
    )]
    public function postInstallScripts(Request $request): JsonResponse
    {
        return $this->providerData($request, 'getPostInstallScripts', 'post-install scripts');
    }

    #[OA\Post(
        path: '/servers/hostinger',
        operationId: 'create-hostinger-server',
        summary: 'Purchase a Hostinger VPS and create a linked server',
        security: [['bearerAuth' => []]],
        tags: ['Hostinger'],
        responses: [
            new OA\Response(response: 201, description: 'Hostinger VPS purchased and linked to a Coolify server.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 422, description: 'Validation failed.'),
            new OA\Response(response: 429, description: 'Hostinger rate limit exceeded.'),
        ]
    )]
    public function createServer(Request $request): JsonResponse
    {
        $allowedFields = [
            'cloud_provider_token_uuid',
            'cloud_provider_token_id',
            'item_id',
            'data_center_id',
            'template_id',
            'name',
            'private_key_uuid',
            'enable_backups',
            'public_key_ids',
            'post_install_script_id',
            'instant_validate',
        ];
        $teamId = getTeamIdFromToken();

        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $this->authorize('create', [Server::class]);

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $validator = customApiValidator($request->all(), [
            'cloud_provider_token_uuid' => 'required_without:cloud_provider_token_id|string',
            'cloud_provider_token_id' => 'required_without:cloud_provider_token_uuid|string',
            'item_id' => 'required|string',
            'data_center_id' => 'required|integer',
            'template_id' => 'required|integer',
            'name' => ['nullable', 'string', 'max:253', new ValidHostname],
            'private_key_uuid' => 'required|string',
            'enable_backups' => 'nullable|boolean',
            'public_key_ids' => 'nullable|array',
            'public_key_ids.*' => 'integer',
            'post_install_script_id' => 'nullable|integer',
            'instant_validate' => 'nullable|boolean',
        ]);
        $extraFields = array_diff(array_keys($request->all()), $allowedFields);

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

        $team = Team::find($teamId);
        if (Team::serverLimitReached($team)) {
            return response()->json(['message' => 'Server limit reached for your subscription.'], 400);
        }

        $token = $this->hostingerToken($request, $teamId);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        $privateKey = PrivateKey::whereTeamId($teamId)->whereUuid($request->private_key_uuid)->first();
        if (! $privateKey) {
            return response()->json(['message' => 'Private key not found.'], 404);
        }

        $virtualMachineId = null;

        try {
            $hostingerService = new HostingerService($token->token);
            $normalizedServerName = strtolower(trim($request->name ?: generate_random_name()));
            $setup = [
                'data_center_id' => $request->integer('data_center_id'),
                'template_id' => $request->integer('template_id'),
                'hostname' => $normalizedServerName,
                'enable_backups' => $request->boolean('enable_backups', true),
                'public_key' => [
                    'name' => $privateKey->name,
                    'key' => $privateKey->getPublicKey(),
                ],
            ];
            if ($request->filled('post_install_script_id')) {
                $setup['post_install_script_id'] = $request->integer('post_install_script_id');
            }

            $virtualMachine = $hostingerService->purchaseVirtualMachine([
                'item_id' => $request->item_id,
                'setup' => $setup,
            ]);
            $virtualMachineId = (int) $virtualMachine['id'];

            $server = Server::create([
                'name' => $normalizedServerName,
                'ip' => Server::PLACEHOLDER_IP,
                'user' => 'root',
                'port' => 22,
                'team_id' => $teamId,
                'private_key_id' => $privateKey->id,
                'cloud_provider_token_id' => $token->id,
                'hostinger_virtual_machine_id' => $virtualMachine['id'],
                'hostinger_virtual_machine_status' => $virtualMachine['state'] ?? null,
            ]);

            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
            $server->save();

            try {
                if ($request->array('public_key_ids')) {
                    $hostingerService->attachPublicKeys((int) $virtualMachine['id'], $request->array('public_key_ids'));
                }
                $virtualMachine = $hostingerService->waitForPublicIp($virtualMachine);
                $ipAddress = $hostingerService->getPublicIpAddress($virtualMachine);
                if ($ipAddress) {
                    $server->update([
                        'ip' => $ipAddress,
                        'hostinger_virtual_machine_status' => $virtualMachine['state'] ?? $server->hostinger_virtual_machine_status,
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
            }

            $ipAddress = $server->fresh()->ip;

            if ($request->boolean('instant_validate') && ! $server->hasPlaceholderIp()) {
                ValidateServer::dispatch($server);
            }

            auditLog('api.hostinger_virtual_machine.created', [
                'team_id' => $teamId,
                'server_uuid' => $server->uuid,
                'server_name' => $server->name,
                'hostinger_virtual_machine_id' => $virtualMachine['id'],
                'ip' => $ipAddress,
            ]);

            return response()->json([
                'uuid' => $server->uuid,
                'hostinger_virtual_machine_id' => $virtualMachine['id'],
                'ip' => $ipAddress,
                'provisioning' => $ipAddress === Server::PLACEHOLDER_IP,
            ])->setStatusCode(201);
        } catch (RateLimitException $e) {
            $response = response()->json(['message' => $e->getMessage()], 429);
            if ($e->retryAfter !== null) {
                $response->header('Retry-After', $e->retryAfter);
            }

            return $response;
        } catch (\Throwable $e) {
            logger()->error('Failed to create Hostinger server', [
                'error' => $e->getMessage(),
                'team_id' => $teamId,
                'hostinger_virtual_machine_id' => $virtualMachineId,
            ]);

            return response()->json(array_filter([
                'message' => $virtualMachineId
                    ? 'The Hostinger VPS was purchased but could not be saved in Coolify. Manage it in hPanel.'
                    : 'Failed to create Hostinger server.',
                'hostinger_virtual_machine_id' => $virtualMachineId,
            ]), 500);
        }
    }

    private function providerData(Request $request, string $method, string $resource): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $token = $this->hostingerToken($request, $teamId);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        try {
            return response()->json((new HostingerService($token->token))->{$method}());
        } catch (\Throwable) {
            return response()->json(['message' => "Failed to fetch Hostinger {$resource}."], 500);
        }
    }

    private function hostingerToken(Request $request, int $teamId): CloudProviderToken|JsonResponse
    {
        $validator = customApiValidator($request->all(), [
            'cloud_provider_token_uuid' => 'required_without:cloud_provider_token_id|string',
            'cloud_provider_token_id' => 'required_without:cloud_provider_token_uuid|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokenUuid = $request->cloud_provider_token_uuid ?? $request->cloud_provider_token_id;
        $token = CloudProviderToken::whereTeamId($teamId)
            ->whereUuid($tokenUuid)
            ->where('provider', 'hostinger')
            ->first();

        if (! $token) {
            return response()->json(['message' => 'Hostinger cloud provider token not found.'], 404);
        }

        $this->authorize('view', $token);

        return $token;
    }
}
