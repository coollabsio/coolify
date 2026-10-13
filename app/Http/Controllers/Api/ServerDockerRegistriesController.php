<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\DockerRegistryLogins;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Throwable;

class ServerDockerRegistriesController extends Controller
{
    private function teamIdOrAbort(): int|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        return $teamId;
    }

    private function findServerForTeam(int $teamId, string $uuid): ?Server
    {
        return Server::whereTeamId($teamId)->whereUuid($uuid)->first();
    }

    private function serverNotReachableResponse(): JsonResponse
    {
        return response()->json(['message' => 'The server is not reachable. Validate the server first.'], 400);
    }

    private function invalidRegistryResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Validation failed.',
            'errors' => ['registry' => ['Enter a registry host, for example ghcr.io or registry.example.com:5000.']],
        ], 422);
    }

    #[OA\Get(
        summary: 'List server registry logins',
        description: 'List the Docker registries a server is logged in to and the registries its resources pull from or push to. Reads the Docker config of the server over SSH. Credentials are never returned, only registry names and usernames. Requires the `read:sensitive` ability.',
        path: '/servers/{uuid}/registries',
        operationId: 'list-server-registries',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Registry logins of the server.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'registries',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'registry', type: 'string', example: 'ghcr.io'),
                                    new OA\Property(property: 'logged_in', type: 'boolean', example: true),
                                    new OA\Property(property: 'source', type: 'string', nullable: true, enum: ['auths', 'credHelpers'], description: 'Where the Docker config stores the credentials.'),
                                    new OA\Property(property: 'username', type: 'string', nullable: true, example: 'octocat'),
                                    new OA\Property(
                                        property: 'used_by',
                                        type: 'array',
                                        items: new OA\Items(
                                            type: 'object',
                                            properties: [
                                                new OA\Property(property: 'type', type: 'string', example: 'Application'),
                                                new OA\Property(property: 'name', type: 'string'),
                                                new OA\Property(property: 'link', type: 'string', nullable: true),
                                            ]
                                        )
                                    ),
                                ]
                            )
                        ),
                        new OA\Property(property: 'error', type: 'string', nullable: true, description: 'Set when the logins could not be read from the server.'),
                    ]
                )
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function index(Request $request, string $uuid): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $server = $this->findServerForTeam($teamId, $uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('update', $server);

        $error = null;
        $loggedIn = [];
        if (! $server->isFunctional()) {
            $error = 'The server is not reachable. Validate the server to read its registry logins.';
        } else {
            try {
                $loggedIn = DockerRegistryLogins::forServer($server);
            } catch (Throwable) {
                $error = 'Could not read the Docker configuration on this server.';
            }
        }

        return response()->json([
            'registries' => DockerRegistryLogins::rows($server, $loggedIn),
            'error' => $error,
        ]);
    }

    #[OA\Post(
        summary: 'Log in to a registry on a server',
        description: 'Run docker login on the server, writing to the Docker config that deployments use. Logging in again to the same registry replaces the saved login.',
        path: '/servers/{uuid}/registries',
        operationId: 'login-server-registry',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['registry', 'username', 'password'],
                type: 'object',
                properties: [
                    new OA\Property(property: 'registry', type: 'string', example: 'ghcr.io', description: 'Registry host, optionally with a port. Use docker.io for Docker Hub.'),
                    new OA\Property(property: 'username', type: 'string', example: 'octocat'),
                    new OA\Property(property: 'password', type: 'string', description: 'Password or access token. Never returned.'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logged in.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Logged in to ghcr.io.'),
                    ]
                )
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function login(Request $request, string $uuid): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $allowedFields = ['registry', 'username', 'password'];
        $input = $request->only($allowedFields);
        if (is_string($input['registry'] ?? null)) {
            $input['registry'] = DockerRegistryLogins::normalizeRegistry($input['registry']);
        }

        $validator = customApiValidator($input, [
            'registry' => ['required', 'string', 'max:255', 'regex:'.DockerRegistryLogins::REGISTRY_PATTERN],
            'username' => ['required', 'string', 'max:255', 'regex:/^[^\\s]+$/'],
            'password' => ['required', 'string', 'max:20000'],
        ], [
            'registry.regex' => 'Enter a registry host, for example ghcr.io or registry.example.com:5000.',
            'username.regex' => 'The username must not contain spaces.',
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

        $server = $this->findServerForTeam($teamId, $uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('update', $server);

        if (! $server->isFunctional()) {
            return $this->serverNotReachableResponse();
        }

        $registry = $input['registry'];

        try {
            DockerRegistryLogins::login($server, $registry, $input['username'], $input['password']);
        } catch (Throwable $exception) {
            DockerRegistryLogins::audit($server, 'login', $registry, succeeded: false, source: 'api');

            return response()->json(['message' => 'Login failed: '.$exception->getMessage()], 400);
        }

        DockerRegistryLogins::audit($server, 'login', $registry, source: 'api');

        return response()->json(['message' => "Logged in to {$registry}."]);
    }

    #[OA\Post(
        summary: 'Check a registry login on a server',
        description: 'Check that the saved login of the server for a registry still works. Runs docker login with the saved credentials on the server.',
        path: '/servers/{uuid}/registries/{registry}/check',
        operationId: 'check-server-registry-login',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'registry', in: 'path', required: true, description: 'Registry host, optionally with a port, for example registry.example.com:5000.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The login works.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'The login for ghcr.io works.'),
                    ]
                )
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function check(Request $request, string $uuid, string $registry): JsonResponse
    {
        return $this->runOnRegistry($uuid, $registry, null, function (Server $server, string $registry) {
            DockerRegistryLogins::checkLogin($server, $registry);

            return "The login for {$registry} works.";
        }, 'Login check failed');
    }

    #[OA\Delete(
        summary: 'Log out from a registry on a server',
        description: 'Run docker logout on the server for a registry.',
        path: '/servers/{uuid}/registries/{registry}',
        operationId: 'logout-server-registry',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'registry', in: 'path', required: true, description: 'Registry host, optionally with a port, for example registry.example.com:5000.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logged out.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Logged out from ghcr.io.'),
                    ]
                )
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function logout(Request $request, string $uuid, string $registry): JsonResponse
    {
        return $this->runOnRegistry($uuid, $registry, 'logout', function (Server $server, string $registry) {
            DockerRegistryLogins::logout($server, $registry);

            return "Logged out from {$registry}.";
        }, 'Logout failed');
    }

    /**
     * @param  ?string  $auditAction  audit event action for changes; null for read-only checks
     * @param  callable(Server, string): string  $operation  runs the command and returns the success message
     */
    private function runOnRegistry(string $uuid, string $registry, ?string $auditAction, callable $operation, string $failure): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $registry = DockerRegistryLogins::normalizeRegistry($registry);
        if (strlen($registry) > 255 || ! preg_match(DockerRegistryLogins::REGISTRY_PATTERN, $registry)) {
            return $this->invalidRegistryResponse();
        }

        $server = $this->findServerForTeam($teamId, $uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('update', $server);

        if (! $server->isFunctional()) {
            return $this->serverNotReachableResponse();
        }

        try {
            $message = $operation($server, $registry);
        } catch (Throwable $exception) {
            if ($auditAction !== null) {
                DockerRegistryLogins::audit($server, $auditAction, $registry, succeeded: false, source: 'api');
            }

            return response()->json(['message' => "{$failure}: {$exception->getMessage()}"], 400);
        }

        if ($auditAction !== null) {
            DockerRegistryLogins::audit($server, $auditAction, $registry, source: 'api');
        }

        return response()->json(['message' => $message]);
    }
}
