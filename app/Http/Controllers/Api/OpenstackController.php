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
use App\Services\OpenStackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class OpenstackController extends Controller
{
    /**
     * Max attempts (and delay per attempt) when polling OpenStack for a
     * bootstrapped port / fixed IP after a server has been requested.
     */
    private const POLL_ATTEMPTS = 20;

    private const POLL_DELAY_SECONDS = 3;

    private function getCloudProviderTokenUuid(Request $request): ?string
    {
        return $request->cloud_provider_token_uuid ?? $request->cloud_provider_token_id;
    }

    /**
     * Resolve the OpenStack cloud provider token for the current team, or return
     * a JSON error response.
     */
    private function resolveToken(int $teamId, Request $request): CloudProviderToken|JsonResponse
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
            ->where('provider', 'openstack')
            ->first();

        if (! $token) {
            return response()->json(['message' => 'OpenStack cloud provider token not found.'], 404);
        }

        return $token;
    }

    #[OA\Get(
        summary: 'Get OpenStack Flavors',
        description: 'Get all available OpenStack flavors (instance sizes).',
        path: '/openstack/flavors',
        operationId: 'get-openstack-flavors',
        security: [['bearerAuth' => []]],
        tags: ['OpenStack'],
        parameters: [
            new OA\Parameter(name: 'cloud_provider_token_uuid', in: 'query', required: true, description: 'Cloud provider token UUID.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of OpenStack flavors.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function flavors(Request $request)
    {
        return $this->listResource($request, fn (OpenStackService $service) => $service->getFlavors(), 'flavors');
    }

    #[OA\Get(
        summary: 'Get OpenStack Images',
        description: 'Get all available OpenStack images.',
        path: '/openstack/images',
        operationId: 'get-openstack-images',
        security: [['bearerAuth' => []]],
        tags: ['OpenStack'],
        parameters: [
            new OA\Parameter(name: 'cloud_provider_token_uuid', in: 'query', required: true, description: 'Cloud provider token UUID.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of OpenStack images.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function images(Request $request)
    {
        return $this->listResource($request, fn (OpenStackService $service) => $service->getImages(), 'images');
    }

    #[OA\Get(
        summary: 'Get OpenStack Networks',
        description: 'Get all available OpenStack networks.',
        path: '/openstack/networks',
        operationId: 'get-openstack-networks',
        security: [['bearerAuth' => []]],
        tags: ['OpenStack'],
        parameters: [
            new OA\Parameter(name: 'cloud_provider_token_uuid', in: 'query', required: true, description: 'Cloud provider token UUID.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of OpenStack networks.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function networks(Request $request)
    {
        return $this->listResource($request, fn (OpenStackService $service) => $service->getNetworks(), 'networks');
    }

    #[OA\Get(
        summary: 'Get OpenStack Availability Zones',
        description: 'Get all available OpenStack compute availability zones.',
        path: '/openstack/availability-zones',
        operationId: 'get-openstack-availability-zones',
        security: [['bearerAuth' => []]],
        tags: ['OpenStack'],
        parameters: [
            new OA\Parameter(name: 'cloud_provider_token_uuid', in: 'query', required: true, description: 'Cloud provider token UUID.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of OpenStack availability zones.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function availabilityZones(Request $request)
    {
        return $this->listResource($request, fn (OpenStackService $service) => $service->getAvailabilityZones(), 'availability zones');
    }

    #[OA\Get(
        summary: 'Get OpenStack Keypairs',
        description: 'Get all available OpenStack keypairs.',
        path: '/openstack/keypairs',
        operationId: 'get-openstack-keypairs',
        security: [['bearerAuth' => []]],
        tags: ['OpenStack'],
        parameters: [
            new OA\Parameter(name: 'cloud_provider_token_uuid', in: 'query', required: true, description: 'Cloud provider token UUID.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of OpenStack keypairs.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function keypairs(Request $request)
    {
        return $this->listResource($request, fn (OpenStackService $service) => $service->getKeypairs(), 'keypairs');
    }

    /**
     * Shared handler for the read-only listing endpoints.
     */
    private function listResource(Request $request, callable $fetch, string $label)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $token = $this->resolveToken($teamId, $request);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        try {
            $service = new OpenStackService($token->credentials());

            return response()->json($fetch($service));
        } catch (\Throwable $e) {
            return response()->json(['message' => "Failed to fetch OpenStack {$label}."], 500);
        }
    }

    #[OA\Post(
        summary: 'Create OpenStack Server',
        description: 'Boot a new OpenStack instance and register it in Coolify.',
        path: '/servers/openstack',
        operationId: 'create-openstack-server',
        security: [['bearerAuth' => []]],
        tags: ['OpenStack'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['flavor', 'image', 'network', 'private_key_uuid'],
                    properties: [
                        'cloud_provider_token_uuid' => ['type' => 'string', 'description' => 'OpenStack cloud provider token UUID.'],
                        'name' => ['type' => 'string', 'description' => 'Server name (auto-generated if omitted).'],
                        'user' => ['type' => 'string', 'description' => 'SSH login user for the image (default: root).'],
                        'flavor' => ['type' => 'string', 'description' => 'Flavor ID.'],
                        'image' => ['type' => 'string', 'description' => 'Image ID.'],
                        'network' => ['type' => 'string', 'description' => 'Private network ID to attach.'],
                        'volume_size' => ['type' => 'integer', 'description' => 'Root volume size in GB. Required for diskless flavors (boot from volume); the volume is deleted with the server.'],
                        'availability_zone' => ['type' => 'string', 'description' => 'Availability zone (optional).'],
                        'assign_floating_ip' => ['type' => 'boolean', 'description' => 'Allocate + attach a floating IP (default: true).'],
                        'external_network' => ['type' => 'string', 'description' => 'External network ID for the floating IP (required when assign_floating_ip is true).'],
                        'private_key_uuid' => ['type' => 'string', 'description' => 'Coolify private key UUID.'],
                        'cloud_init_script' => ['type' => 'string', 'description' => 'Optional cloud-init user data.'],
                        'instant_validate' => ['type' => 'boolean', 'description' => 'Validate the server immediately after creation.'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'OpenStack server created.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function createServer(Request $request)
    {
        $allowedFields = [
            'cloud_provider_token_uuid',
            'cloud_provider_token_id',
            'name',
            'user',
            'flavor',
            'image',
            'network',
            'volume_size',
            'availability_zone',
            'assign_floating_ip',
            'external_network',
            'private_key_uuid',
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
            'name' => ['nullable', 'string', 'max:253', new ValidHostname],
            'user' => 'nullable|string|max:255',
            'flavor' => 'required|string',
            'image' => 'required|string',
            'network' => 'required|string',
            'volume_size' => 'nullable|integer|min:1|max:16384',
            'availability_zone' => 'nullable|string',
            'assign_floating_ip' => 'nullable|boolean',
            'external_network' => 'nullable|string|required_if:assign_floating_ip,true',
            'private_key_uuid' => 'required|string',
            'cloud_init_script' => ['nullable', 'string', new ValidCloudInitYaml],
            'instant_validate' => 'nullable|boolean',
        ]);

        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
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
        $assignFloatingIp = $request->boolean('assign_floating_ip', true);
        $serverUser = $request->user ?: 'root';

        // When a floating IP is requested an external network is required.
        if ($assignFloatingIp && ! $request->external_network) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['external_network' => ['An external network is required when assign_floating_ip is true.']],
            ], 422);
        }

        $token = $this->resolveToken($teamId, $request);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        $privateKey = PrivateKey::whereTeamId($teamId)->whereUuid($request->private_key_uuid)->first();
        if (! $privateKey) {
            return response()->json(['message' => 'Private key not found.'], 404);
        }

        try {
            $service = new OpenStackService($token->credentials());

            // Ensure the keypair exists on OpenStack (matched by name).
            $keyName = $privateKey->name;
            if (! $service->findKeypairByName($keyName)) {
                $uploaded = $service->uploadKeypair($keyName, $privateKey->getPublicKey());
                $keyName = $uploaded['name'] ?? $keyName;
            }

            // Ensure the instance is reachable (OpenStack's default security
            // group blocks external SSH/HTTP). Attached to the port after boot.
            $securityGroupId = $service->ensureCoolifySecurityGroup();

            $normalizedServerName = strtolower(trim($request->name));

            $openstackServer = $service->createServer([
                'name' => $normalizedServerName,
                'imageRef' => $request->image,
                'flavorRef' => $request->flavor,
                'networkId' => $request->network,
                'key_name' => $keyName,
                'availabilityZone' => $request->availability_zone,
                'volumeSize' => $request->volume_size,
                'userData' => $request->cloud_init_script,
            ]);

            $openstackServerId = $openstackServer['id'] ?? null;
            if (! $openstackServerId) {
                throw new \Exception('OpenStack did not return a server id.');
            }

            $address = $this->resolveServerAddress($service, $openstackServerId, $securityGroupId, $assignFloatingIp, $request->external_network);

            $server = Server::create([
                'name' => $normalizedServerName,
                'ip' => $address['ip'],
                'user' => $serverUser,
                'port' => 22,
                'team_id' => $teamId,
                'private_key_id' => $privateKey->id,
                'cloud_provider_token_id' => $token->id,
                'openstack_server_id' => $openstackServerId,
                'openstack_floating_ip_id' => $address['floating_ip_id'],
            ]);

            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
            $server->save();

            if ($request->boolean('instant_validate')) {
                ValidateServer::dispatch($server);
            }

            auditLog('api.openstack_server.created', [
                'team_id' => $teamId,
                'server_uuid' => $server->uuid,
                'server_name' => $server->name,
                'openstack_server_id' => $openstackServerId,
                'ip' => $address['ip'],
            ]);

            return response()->json([
                'uuid' => $server->uuid,
                'openstack_server_id' => $openstackServerId,
                'ip' => $address['ip'],
            ])->setStatusCode(201);
        } catch (RateLimitException $e) {
            $response = response()->json(['message' => $e->getMessage()], 429);
            if ($e->retryAfter !== null) {
                $response->header('Retry-After', $e->retryAfter);
            }

            return $response;
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to create OpenStack server: '.$e->getMessage()], 500);
        }
    }

    /**
     * @return array{ip: string, floating_ip_id: ?string}
     */
    private function resolveServerAddress(OpenStackService $service, string $serverId, string $securityGroupId, bool $assignFloatingIp, ?string $externalNetworkId): array
    {
        // Wait for the instance's network port (needed to attach the security
        // group and to associate a floating IP).
        $portId = null;
        for ($attempt = 0; $attempt < self::POLL_ATTEMPTS; $attempt++) {
            $portId = $service->getServerPortId($serverId);
            if ($portId) {
                break;
            }
            sleep(self::POLL_DELAY_SECONDS);
        }

        if (! $portId) {
            throw new \Exception('Timed out waiting for the OpenStack instance network port.');
        }

        // Open the ports Coolify needs on top of the default group.
        $service->attachSecurityGroupToPort($portId, $securityGroupId);

        if ($assignFloatingIp) {
            $floatingIp = $service->allocateFloatingIp($externalNetworkId, $portId);
            $ip = $floatingIp['floating_ip_address'] ?? null;

            if (! $ip) {
                throw new \Exception('Failed to allocate a floating IP for the OpenStack instance.');
            }

            return ['ip' => $ip, 'floating_ip_id' => $floatingIp['id'] ?? null];
        }

        for ($attempt = 0; $attempt < self::POLL_ATTEMPTS; $attempt++) {
            $server = $service->getServer($serverId);
            $ip = $service->getServerFixedIp($server);
            if ($ip) {
                return ['ip' => $ip, 'floating_ip_id' => null];
            }
            sleep(self::POLL_DELAY_SECONDS);
        }

        throw new \Exception('Timed out waiting for the OpenStack instance to receive an IP address.');
    }
}
