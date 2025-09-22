<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class GithubController extends Controller
{
    #[OA\Post(
        summary: 'Create GitHub App',
        description: 'Create a new GitHub app.',
        path: '/github-apps',
        operationId: 'create-github-app',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['GitHub Apps'],
        requestBody: new OA\RequestBody(
            description: 'GitHub app creation payload.',
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'name' => ['type' => 'string', 'description' => 'Name of the GitHub app.'],
                            'organization' => ['type' => 'string', 'nullable' => true, 'description' => 'Organization to associate the app with.'],
                            'api_url' => ['type' => 'string', 'description' => 'API URL for the GitHub app (e.g., https://api.github.com).'],
                            'html_url' => ['type' => 'string', 'description' => 'HTML URL for the GitHub app (e.g., https://github.com).'],
                            'custom_user' => ['type' => 'string', 'description' => 'Custom user for SSH access (default: git).'],
                            'custom_port' => ['type' => 'integer', 'description' => 'Custom port for SSH access (default: 22).'],
                            'app_id' => ['type' => 'integer', 'description' => 'GitHub App ID from GitHub.'],
                            'installation_id' => ['type' => 'integer', 'description' => 'GitHub Installation ID.'],
                            'client_id' => ['type' => 'string', 'description' => 'GitHub OAuth App Client ID.'],
                            'client_secret' => ['type' => 'string', 'description' => 'GitHub OAuth App Client Secret.'],
                            'webhook_secret' => ['type' => 'string', 'description' => 'Webhook secret for GitHub webhooks.'],
                            'private_key_uuid' => ['type' => 'string', 'description' => 'UUID of an existing private key for GitHub App authentication.'],
                            'is_public' => ['type' => 'boolean', 'description' => 'Whether this is a public GitHub app (default: false).'],
                            'is_system_wide' => ['type' => 'boolean', 'description' => 'Is this app system-wide (cloud only).'],
                        ],
                        required: ['name', 'api_url', 'html_url', 'app_id', 'installation_id', 'client_id', 'client_secret', 'private_key_uuid'],
                    ),
                ),
            ],
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'GitHub app created successfully.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'id' => ['type' => 'integer'],
                                'uuid' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'organization' => ['type' => 'string', 'nullable' => true],
                                'api_url' => ['type' => 'string'],
                                'html_url' => ['type' => 'string'],
                                'custom_user' => ['type' => 'string'],
                                'custom_port' => ['type' => 'integer'],
                                'app_id' => ['type' => 'integer'],
                                'installation_id' => ['type' => 'integer'],
                                'client_id' => ['type' => 'string'],
                                'private_key_id' => ['type' => 'integer'],
                                'is_public' => ['type' => 'boolean'],
                                'is_system_wide' => ['type' => 'boolean'],
                                'team_id' => ['type' => 'integer'],
                            ]
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_github_app(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        $allowedFields = [
            'name',
            'organization',
            'api_url',
            'html_url',
            'custom_user',
            'custom_port',
            'app_id',
            'installation_id',
            'client_id',
            'client_secret',
            'webhook_secret',
            'private_key_uuid',
            'is_public',
            'is_system_wide',
        ];

        $validator = customApiValidator($request->all(), [
            'name' => 'required|string|max:255',
            'organization' => 'nullable|string|max:255',
            'api_url' => 'required|string|url',
            'html_url' => 'required|string|url',
            'custom_user' => 'string|max:255',
            'custom_port' => 'integer|min:1|max:65535',
            'app_id' => 'required|integer',
            'installation_id' => 'required|integer',
            'client_id' => 'required|string|max:255',
            'client_secret' => 'required|string',
            'webhook_secret' => 'required|string',
            'private_key_uuid' => 'required|string',
            'is_public' => 'boolean',
            'is_system_wide' => 'boolean',
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

        try {
            // Verify the private key belongs to the team
            $privateKey = PrivateKey::where('uuid', $request->input('private_key_uuid'))
                ->where('team_id', $teamId)
                ->first();

            if (! $privateKey) {
                return response()->json([
                    'message' => 'Private key not found or does not belong to your team.',
                ], 404);
            }

            $payload = [
                'uuid' => Str::uuid(),
                'name' => $request->input('name'),
                'organization' => $request->input('organization'),
                'api_url' => $request->input('api_url'),
                'html_url' => $request->input('html_url'),
                'custom_user' => $request->input('custom_user', 'git'),
                'custom_port' => $request->input('custom_port', 22),
                'app_id' => $request->input('app_id'),
                'installation_id' => $request->input('installation_id'),
                'client_id' => $request->input('client_id'),
                'client_secret' => $request->input('client_secret'),
                'webhook_secret' => $request->input('webhook_secret'),
                'private_key_id' => $privateKey->id,
                'is_public' => $request->input('is_public', false),
                'team_id' => $teamId,
            ];

            if (! isCloud()) {
                $payload['is_system_wide'] = $request->input('is_system_wide', false);
            }

            $githubApp = GithubApp::create($payload);

            return response()->json($githubApp, 201);
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }

    #[OA\Get(
        path: '/github-apps/{github_app_id}/repositories',
        summary: 'Load Repositories for a GitHub App',
        description: 'Fetch repositories from GitHub for a given GitHub app.',
        operationId: 'load-repositories',
        tags: ['GitHub Apps'],
        security: [
            ['bearerAuth' => []],
        ],
        parameters: [
            new OA\Parameter(
                name: 'github_app_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'GitHub App ID'
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Repositories loaded successfully.',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'repositories' => new OA\Items(
                                type: 'array',
                                items: new OA\Schema(type: 'object')
                            ),
                        ]
                    )
                )
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function load_repositories($github_app_id)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        try {
            $githubApp = GithubApp::where('id', $github_app_id)
                ->where('team_id', $teamId)
                ->firstOrFail();

            $token = generateGithubInstallationToken($githubApp);
            $repositories = collect();
            $page = 1;
            $maxPages = 100; // Safety limit: max 10,000 repositories

            while ($page <= $maxPages) {
                $response = Http::withToken($token)->get("{$githubApp->api_url}/installation/repositories", [
                    'per_page' => 100,
                    'page' => $page,
                ]);

                if ($response->status() !== 200) {
                    return response()->json([
                        'message' => $response->json()['message'] ?? 'Failed to load repositories',
                    ], $response->status());
                }

                $json = $response->json();
                $repos = $json['repositories'] ?? [];

                if (empty($repos)) {
                    break; // No more repositories to load
                }

                $repositories = $repositories->concat($repos);
                $page++;
            }

            return response()->json([
                'repositories' => $repositories->sortBy('name')->values(),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'GitHub app not found'], 404);
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }

    #[OA\Get(
        path: '/github-apps/{github_app_id}/repositories/{owner}/{repo}/branches',
        summary: 'Load Branches for a GitHub Repository',
        description: 'Fetch branches from GitHub for a given repository.',
        operationId: 'load-branches',
        tags: ['GitHub Apps'],
        security: [
            ['bearerAuth' => []],
        ],
        parameters: [
            new OA\Parameter(
                name: 'github_app_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'GitHub App ID'
            ),
            new OA\Parameter(
                name: 'owner',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Repository owner'
            ),
            new OA\Parameter(
                name: 'repo',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Repository name'
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Branches loaded successfully.',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'branches' => new OA\Items(
                                type: 'array',
                                items: new OA\Schema(type: 'object')
                            ),
                        ]
                    )
                )
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function load_branches($github_app_id, $owner, $repo)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        try {
            $githubApp = GithubApp::where('id', $github_app_id)
                ->where('team_id', $teamId)
                ->firstOrFail();

            $token = generateGithubInstallationToken($githubApp);

            $response = Http::withToken($token)->get("{$githubApp->api_url}/repos/{$owner}/{$repo}/branches");

            if ($response->status() !== 200) {
                return response()->json([
                    'message' => 'Error loading branches from GitHub.',
                    'error' => $response->json('message'),
                ], $response->status());
            }

            $branches = $response->json();

            return response()->json([
                'branches' => $branches,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'GitHub app not found'], 404);
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }
}
