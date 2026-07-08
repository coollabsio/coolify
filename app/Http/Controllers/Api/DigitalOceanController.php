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
use App\Services\DigitalOceanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DigitalOceanController extends Controller
{
    private function getCloudProviderTokenUuid(Request $request): ?string
    {
        return $request->cloud_provider_token_uuid ?? $request->cloud_provider_token_id;
    }

    private function digitalOceanToken(Request $request, int $teamId): CloudProviderToken|JsonResponse
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

        $token = CloudProviderToken::whereTeamId($teamId)
            ->whereUuid($this->getCloudProviderTokenUuid($request))
            ->where('provider', 'digitalocean')
            ->first();

        if (! $token) {
            return response()->json(['message' => 'DigitalOcean cloud provider token not found.'], 404);
        }

        $this->authorize('view', $token);

        return $token;
    }

    #[OA\Get(
        path: '/digitalocean/regions',
        operationId: 'get-digitalocean-regions',
        summary: 'Get DigitalOcean regions',
        security: [['bearerAuth' => []]],
        tags: ['DigitalOcean'],
        parameters: [
            new OA\Parameter(name: 'cloud_provider_token_uuid', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'cloud_provider_token_id', in: 'query', required: false, deprecated: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of DigitalOcean regions.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ]
    )]
    public function regions(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $token = $this->digitalOceanToken($request, $teamId);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        try {
            return response()->json((new DigitalOceanService($token->token))->getRegions());
        } catch (\Throwable) {
            return response()->json(['message' => 'Failed to fetch DigitalOcean regions.'], 500);
        }
    }

    #[OA\Get(
        path: '/digitalocean/sizes',
        operationId: 'get-digitalocean-sizes',
        summary: 'Get DigitalOcean sizes',
        security: [['bearerAuth' => []]],
        tags: ['DigitalOcean'],
        parameters: [
            new OA\Parameter(name: 'cloud_provider_token_uuid', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'cloud_provider_token_id', in: 'query', required: false, deprecated: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of DigitalOcean sizes.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ]
    )]
    public function sizes(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $token = $this->digitalOceanToken($request, $teamId);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        try {
            return response()->json((new DigitalOceanService($token->token))->getSizes());
        } catch (\Throwable) {
            return response()->json(['message' => 'Failed to fetch DigitalOcean sizes.'], 500);
        }
    }

    #[OA\Get(
        path: '/digitalocean/images',
        operationId: 'get-digitalocean-images',
        summary: 'Get DigitalOcean images',
        security: [['bearerAuth' => []]],
        tags: ['DigitalOcean'],
        parameters: [
            new OA\Parameter(name: 'cloud_provider_token_uuid', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'cloud_provider_token_id', in: 'query', required: false, deprecated: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of DigitalOcean images.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ]
    )]
    public function images(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $token = $this->digitalOceanToken($request, $teamId);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        try {
            return response()->json((new DigitalOceanService($token->token))->getImages());
        } catch (\Throwable) {
            return response()->json(['message' => 'Failed to fetch DigitalOcean images.'], 500);
        }
    }

    #[OA\Get(
        path: '/digitalocean/ssh-keys',
        operationId: 'get-digitalocean-ssh-keys',
        summary: 'Get DigitalOcean SSH keys',
        security: [['bearerAuth' => []]],
        tags: ['DigitalOcean'],
        parameters: [
            new OA\Parameter(name: 'cloud_provider_token_uuid', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'cloud_provider_token_id', in: 'query', required: false, deprecated: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of DigitalOcean SSH keys.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ]
    )]
    public function sshKeys(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $token = $this->digitalOceanToken($request, $teamId);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        try {
            return response()->json((new DigitalOceanService($token->token))->getSshKeys());
        } catch (\Throwable) {
            return response()->json(['message' => 'Failed to fetch DigitalOcean SSH keys.'], 500);
        }
    }

    #[OA\Post(
        path: '/servers/digitalocean',
        operationId: 'create-digitalocean-server',
        summary: 'Create a server on DigitalOcean',
        security: [['bearerAuth' => []]],
        tags: ['DigitalOcean'],
        responses: [
            new OA\Response(response: 201, description: 'DigitalOcean droplet created and linked to a Coolify server.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 422, description: 'Validation failed.'),
            new OA\Response(response: 429, description: 'DigitalOcean rate limit exceeded.'),
        ]
    )]
    public function createServer(Request $request): JsonResponse
    {
        $allowedFields = [
            'cloud_provider_token_uuid',
            'cloud_provider_token_id',
            'region',
            'size',
            'image',
            'name',
            'private_key_uuid',
            'enable_ipv6',
            'monitoring',
            'digitalocean_ssh_key_ids',
            'cloud_init_script',
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
            'region' => 'required|string',
            'size' => 'required|string',
            'image' => 'required',
            'name' => ['nullable', 'string', 'max:253', new ValidHostname],
            'private_key_uuid' => 'required|string',
            'enable_ipv6' => 'nullable|boolean',
            'monitoring' => 'nullable|boolean',
            'digitalocean_ssh_key_ids' => 'nullable|array',
            'digitalocean_ssh_key_ids.*' => 'integer',
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

        $request->offsetSet('name', $request->name ?: generate_random_name());
        $request->offsetSet('enable_ipv6', $request->boolean('enable_ipv6', true));
        $request->offsetSet('monitoring', $request->boolean('monitoring', true));
        $request->offsetSet('digitalocean_ssh_key_ids', $request->digitalocean_ssh_key_ids ?? []);
        $request->offsetSet('instant_validate', $request->boolean('instant_validate', false));

        $token = $this->digitalOceanToken($request, $teamId);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        $privateKey = PrivateKey::whereTeamId($teamId)->whereUuid($request->private_key_uuid)->first();
        if (! $privateKey) {
            return response()->json(['message' => 'Private key not found.'], 404);
        }

        try {
            $digitalOceanService = new DigitalOceanService($token->token);
            $sshKeyId = $this->getOrCreateSshKey($digitalOceanService, $privateKey);

            $sshKeys = array_values(array_unique(array_merge(
                [$sshKeyId],
                $request->digitalocean_ssh_key_ids
            )));

            $normalizedServerName = strtolower(trim($request->name));
            $params = [
                'name' => $normalizedServerName,
                'region' => $request->region,
                'size' => $request->size,
                'image' => $request->image,
                'ssh_keys' => $sshKeys,
                'ipv6' => $request->enable_ipv6,
                'monitoring' => $request->monitoring,
            ];

            if (! empty($request->cloud_init_script)) {
                $params['user_data'] = $request->cloud_init_script;
            }

            $droplet = $digitalOceanService->createDroplet($params);
            $dropletId = (int) $droplet['id'];
            $droplet = $digitalOceanService->waitForPublicIp($droplet, true, $request->enable_ipv6);
            $ipAddress = $digitalOceanService->getPublicIpAddress($droplet, true, $request->enable_ipv6);

            if (! $ipAddress) {
                throw new \Exception('No public IP address available for the new droplet.');
            }

            $server = Server::create([
                'name' => $normalizedServerName,
                'ip' => $ipAddress,
                'user' => 'root',
                'port' => 22,
                'team_id' => $teamId,
                'private_key_id' => $privateKey->id,
                'cloud_provider_token_id' => $token->id,
                'digitalocean_droplet_id' => $dropletId,
                'digitalocean_droplet_status' => $droplet['status'] ?? null,
            ]);

            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
            $server->save();

            if ($request->instant_validate) {
                ValidateServer::dispatch($server);
            }

            auditLog('api.digitalocean_droplet.created', [
                'team_id' => $teamId,
                'server_uuid' => $server->uuid,
                'server_name' => $server->name,
                'digitalocean_droplet_id' => $dropletId,
                'ip' => $ipAddress,
            ]);

            return response()->json([
                'uuid' => $server->uuid,
                'digitalocean_droplet_id' => $dropletId,
                'ip' => $ipAddress,
            ])->setStatusCode(201);
        } catch (RateLimitException $e) {
            $response = response()->json(['message' => $e->getMessage()], 429);
            if ($e->retryAfter !== null) {
                $response->header('Retry-After', $e->retryAfter);
            }

            return $response;
        } catch (\Throwable $e) {
            logger()->error('Failed to create DigitalOcean server', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to create DigitalOcean server.'], 500);
        }
    }

    private function getOrCreateSshKey(DigitalOceanService $digitalOceanService, PrivateKey $privateKey): int
    {
        $md5Fingerprint = PrivateKey::generateMd5Fingerprint($privateKey->private_key);

        foreach ($digitalOceanService->getSshKeys() as $key) {
            if (($key['fingerprint'] ?? null) === $md5Fingerprint) {
                return (int) $key['id'];
            }
        }

        $uploadedKey = $digitalOceanService->uploadSshKey($privateKey->name, $privateKey->getPublicKey());

        return (int) $uploadedKey['id'];
    }
}
