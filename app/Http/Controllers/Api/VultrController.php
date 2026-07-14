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
use App\Rules\ValidCloudInitYaml;
use App\Rules\ValidHostname;
use App\Services\VultrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class VultrController extends Controller
{
    private function getCloudProviderTokenUuid(Request $request): ?string
    {
        return $request->cloud_provider_token_uuid ?? $request->cloud_provider_token_id;
    }

    private function getVultrToken(Request $request): CloudProviderToken|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

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

        $token = CloudProviderToken::whereTeamId($teamId)
            ->whereUuid($this->getCloudProviderTokenUuid($request))
            ->where('provider', 'vultr')
            ->first();

        if (! $token) {
            return response()->json(['message' => 'Vultr cloud provider token not found.'], 404);
        }

        $this->authorize('view', $token);

        return $token;
    }

    #[OA\Get(
        summary: 'Get Vultr Regions',
        description: 'Get all available Vultr regions.',
        path: '/vultr/regions',
        operationId: 'get-vultr-regions',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Vultr'],
        responses: [
            new OA\Response(response: 200, description: 'List of Vultr regions.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function regions(Request $request): JsonResponse
    {
        $token = $this->getVultrToken($request);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        try {
            return response()->json((new VultrService($token->token))->getRegions());
        } catch (\Throwable) {
            return response()->json(['message' => 'Failed to fetch Vultr regions.'], 500);
        }
    }

    #[OA\Get(
        summary: 'Get Vultr Plans',
        description: 'Get all available Vultr plans.',
        path: '/vultr/plans',
        operationId: 'get-vultr-plans',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Vultr'],
        responses: [
            new OA\Response(response: 200, description: 'List of Vultr plans.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function plans(Request $request): JsonResponse
    {
        $token = $this->getVultrToken($request);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        try {
            return response()->json((new VultrService($token->token))->getPlans());
        } catch (\Throwable) {
            return response()->json(['message' => 'Failed to fetch Vultr plans.'], 500);
        }
    }

    #[OA\Get(
        summary: 'Get Vultr Operating Systems',
        description: 'Get all available Vultr operating systems.',
        path: '/vultr/os',
        operationId: 'get-vultr-operating-systems',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Vultr'],
        responses: [
            new OA\Response(response: 200, description: 'List of Vultr operating systems.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function operatingSystems(Request $request): JsonResponse
    {
        $token = $this->getVultrToken($request);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        try {
            return response()->json((new VultrService($token->token))->getOperatingSystems());
        } catch (\Throwable) {
            return response()->json(['message' => 'Failed to fetch Vultr operating systems.'], 500);
        }
    }

    #[OA\Get(
        summary: 'Get Vultr SSH Keys',
        description: 'Get all Vultr SSH keys available to the selected token.',
        path: '/vultr/ssh-keys',
        operationId: 'get-vultr-ssh-keys',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Vultr'],
        responses: [
            new OA\Response(response: 200, description: 'List of Vultr SSH keys.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function sshKeys(Request $request): JsonResponse
    {
        $token = $this->getVultrToken($request);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        try {
            return response()->json((new VultrService($token->token))->getSshKeys());
        } catch (\Throwable) {
            return response()->json(['message' => 'Failed to fetch Vultr SSH keys.'], 500);
        }
    }

    #[OA\Post(
        summary: 'Create Vultr Server',
        description: 'Create a Vultr instance and link it as a Coolify server.',
        path: '/servers/vultr',
        operationId: 'create-vultr-server',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Vultr'],
        responses: [
            new OA\Response(response: 201, description: 'Vultr server created.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 422, description: 'Validation failed.'),
            new OA\Response(response: 429, description: 'Vultr API rate limit exceeded.'),
        ]
    )]
    public function createServer(Request $request): JsonResponse
    {
        $allowedFields = [
            'cloud_provider_token_uuid',
            'cloud_provider_token_id',
            'region',
            'plan',
            'os_id',
            'name',
            'private_key_uuid',
            'enable_ipv6',
            'disable_public_ipv4',
            'vultr_ssh_key_ids',
            'cloud_init_script',
            'instant_validate',
        ];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $validator = customApiValidator($request->all(), [
            'cloud_provider_token_uuid' => 'required_without:cloud_provider_token_id|string',
            'cloud_provider_token_id' => 'required_without:cloud_provider_token_uuid|string',
            'region' => 'required|string',
            'plan' => 'required|string',
            'os_id' => 'required|integer',
            'name' => ['nullable', 'string', 'max:253', new ValidHostname],
            'private_key_uuid' => 'required|string',
            'enable_ipv6' => 'nullable|boolean',
            'disable_public_ipv4' => 'nullable|boolean',
            'vultr_ssh_key_ids' => 'nullable|array',
            'vultr_ssh_key_ids.*' => 'string',
            'cloud_init_script' => ['nullable', 'string', new ValidCloudInitYaml],
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

        if (! $request->name) {
            $request->offsetSet('name', generate_random_name());
        }
        if (is_null($request->enable_ipv6)) {
            $request->offsetSet('enable_ipv6', true);
        }
        if (is_null($request->disable_public_ipv4)) {
            $request->offsetSet('disable_public_ipv4', false);
        }
        if (is_null($request->vultr_ssh_key_ids)) {
            $request->offsetSet('vultr_ssh_key_ids', []);
        }
        if (is_null($request->instant_validate)) {
            $request->offsetSet('instant_validate', false);
        }

        if ($request->disable_public_ipv4 && ! $request->enable_ipv6) {
            return $this->networkConfigurationErrorResponse();
        }

        $token = CloudProviderToken::whereTeamId($teamId)
            ->whereUuid($this->getCloudProviderTokenUuid($request))
            ->where('provider', 'vultr')
            ->first();

        if (! $token) {
            return response()->json(['message' => 'Vultr cloud provider token not found.'], 404);
        }

        $this->authorize('view', $token);

        $privateKey = PrivateKey::whereTeamId($teamId)->whereUuid($request->private_key_uuid)->first();
        if (! $privateKey) {
            return response()->json(['message' => 'Private key not found.'], 404);
        }

        try {
            $vultrService = new VultrService($token->token);
            $publicKey = $privateKey->getPublicKey();
            $existingKey = $this->findMatchingSshKey($vultrService->getSshKeys(), $publicKey);

            if ($existingKey) {
                $sshKeyId = $existingKey['id'];
            } else {
                $uploadedKey = $vultrService->uploadSshKey($privateKey->name, $publicKey);
                $sshKeyId = $uploadedKey['id'];
            }

            $normalizedServerName = strtolower(trim($request->name));
            $sshKeys = array_values(array_unique(array_merge([$sshKeyId], $request->vultr_ssh_key_ids)));

            $params = [
                'region' => $request->region,
                'plan' => $request->plan,
                'os_id' => $request->os_id,
                'label' => $normalizedServerName,
                'hostname' => $normalizedServerName,
                'sshkey_id' => $sshKeys,
                'enable_ipv6' => $request->enable_ipv6,
                'disable_public_ipv4' => $request->disable_public_ipv4,
            ];

            if (! empty($request->cloud_init_script)) {
                $params['user_data'] = $request->cloud_init_script;
            }

            $vultrInstance = $vultrService->createInstance($params);
            $ipAddress = $vultrService->getPublicIp($vultrInstance, $request->disable_public_ipv4, $request->enable_ipv6) ?? Server::PLACEHOLDER_IP;

            $server = Server::create([
                'name' => $normalizedServerName,
                'ip' => $ipAddress,
                'user' => 'root',
                'port' => 22,
                'team_id' => $teamId,
                'private_key_id' => $privateKey->id,
                'cloud_provider_token_id' => $token->id,
                'vultr_instance_id' => $vultrInstance['id'],
                'vultr_instance_status' => $vultrInstance['status'] ?? null,
            ]);

            $vultrInstance = $vultrService->waitForPublicIp($vultrInstance, $request->disable_public_ipv4, $request->enable_ipv6);
            $assignedIpAddress = $vultrService->getPublicIp($vultrInstance, $request->disable_public_ipv4, $request->enable_ipv6);
            if ($assignedIpAddress && $assignedIpAddress !== $server->ip) {
                $ipAddress = $assignedIpAddress;
                $server->update([
                    'ip' => $assignedIpAddress,
                    'vultr_instance_status' => $vultrInstance['status'] ?? $server->vultr_instance_status,
                ]);
            }

            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
            $server->save();

            if ($request->instant_validate) {
                ValidateServer::dispatch($server);
            }

            auditLog('api.vultr_server.created', [
                'team_id' => $teamId,
                'server_uuid' => $server->uuid,
                'server_name' => $server->name,
                'vultr_instance_id' => $vultrInstance['id'],
                'ip' => $ipAddress,
            ]);

            return response()->json([
                'uuid' => $server->uuid,
                'vultr_instance_id' => $vultrInstance['id'],
                'ip' => $ipAddress,
            ])->setStatusCode(201);
        } catch (RateLimitException $e) {
            $response = response()->json(['message' => $e->getMessage()], 429);
            if ($e->retryAfter !== null) {
                $response->header('Retry-After', $e->retryAfter);
            }

            return $response;
        } catch (\Throwable) {
            return response()->json(['message' => 'Failed to create Vultr server.'], 500);
        }
    }

    private function findMatchingSshKey(array $sshKeys, string $publicKey): ?array
    {
        $normalizedPublicKey = $this->normalizePublicKey($publicKey);

        foreach ($sshKeys as $sshKey) {
            if ($this->normalizePublicKey($sshKey['ssh_key'] ?? '') === $normalizedPublicKey) {
                return $sshKey;
            }
        }

        return null;
    }

    private function normalizePublicKey(string $publicKey): string
    {
        $parts = preg_split('/\s+/', trim($publicKey));

        return implode(' ', array_slice($parts ?: [], 0, 2));
    }

    private function networkConfigurationErrorResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Validation failed.',
            'errors' => [
                'enable_ipv6' => ['Enable IPv6 when disabling public IPv4.'],
            ],
        ], 422);
    }
}
