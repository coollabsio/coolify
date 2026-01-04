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
    private function removeSensitiveData($githubApp)
    {
        $githubApp->makeHidden([
            'client_secret',
            'webhook_secret',
        ]);

        return serializeApiResponse($githubApp);
    }

    #[OA\Get(
        summary: 'List',
        description: 'List all GitHub apps.',
        path: '/github-apps',
        operationId: 'list-github-apps',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['GitHub Apps'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of GitHub apps.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(
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
                                    'is_system_wide' => ['type' => 'boolean'],
                                    'is_public' => ['type' => 'boolean'],
                                    'team_id' => ['type' => 'integer'],
                                    'type' => ['type' => 'string'],
                                ]
                            )
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
        ]
    )]
    public function list_github_apps(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $githubApps = GithubApp::where(function ($query) use ($teamId) {
            $query->where('team_id', $teamId)
                ->orWhere('is_system_wide', true);
        })->get();

        $githubApps = $githubApps->map(function ($app) {
            return $this->removeSensitiveData($app);
        });

        return response()->json($githubApps);
    }

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
            'is_system_wide',
        ];

        $validator = customApiValidator($request->all(), [
            'name' => 'required|string|max:255',
            'organization' => 'nullable|string|max:255',
            'api_url' => 'required|string|url',
            'html_url' => 'required|string|url',
            'custom_user' => 'nullable|string|max:255',
            'custom_port' => 'nullable|integer|min:1|max:65535',
            'app_id' => 'required|integer',
            'installation_id' => 'required|integer',
            'client_id' => 'required|string|max:255',
            'client_secret' => 'required|string',
            'webhook_secret' => 'required|string',
            'private_key_uuid' => 'required|string',
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
                'is_public' => false,
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
                            new OA\Property(
                                property: 'repositories',
                                type: 'array',
                                items: new OA\Items(type: 'object')
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
                $response = Http::GitHub($githubApp->api_url, $token)
                    ->timeout(20)
                    ->retry(3, 200, throw: false)
                    ->get('/installation/repositories', [
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
                            new OA\Property(
                                property: 'branches',
                                type: 'array',
                                items: new OA\Items(type: 'object')
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

            $response = Http::GitHub($githubApp->api_url, $token)
                ->timeout(20)
                ->retry(3, 200, throw: false)
                ->get("/repos/{$owner}/{$repo}/branches");

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

    /**
     * Update a GitHub app.
     */
    #[OA\Patch(
        path: '/github-apps/{github_app_id}',
        operationId: 'updateGithubApp',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['GitHub Apps'],
        summary: 'Update GitHub App',
        description: 'Update an existing GitHub app.',
        parameters: [
            new OA\Parameter(
                name: 'github_app_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'GitHub App ID'
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => ['type' => 'string', 'description' => 'GitHub App name'],
                        'organization' => ['type' => 'string', 'nullable' => true, 'description' => 'GitHub organization'],
                        'api_url' => ['type' => 'string', 'description' => 'GitHub API URL'],
                        'html_url' => ['type' => 'string', 'description' => 'GitHub HTML URL'],
                        'custom_user' => ['type' => 'string', 'description' => 'Custom user for SSH'],
                        'custom_port' => ['type' => 'integer', 'description' => 'Custom port for SSH'],
                        'app_id' => ['type' => 'integer', 'description' => 'GitHub App ID'],
                        'installation_id' => ['type' => 'integer', 'description' => 'GitHub Installation ID'],
                        'client_id' => ['type' => 'string', 'description' => 'GitHub Client ID'],
                        'client_secret' => ['type' => 'string', 'description' => 'GitHub Client Secret'],
                        'webhook_secret' => ['type' => 'string', 'description' => 'GitHub Webhook Secret'],
                        'private_key_uuid' => ['type' => 'string', 'description' => 'Private key UUID'],
                        'is_system_wide' => ['type' => 'boolean', 'description' => 'Is system wide (non-cloud instances only)'],
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'GitHub app updated successfully',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'message' => ['type' => 'string', 'example' => 'GitHub app updated successfully'],
                            'data' => ['type' => 'object', 'description' => 'Updated GitHub app data'],
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'GitHub app not found'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update_github_app(Request $request, $github_app_id)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        try {
            $githubApp = GithubApp::where('id', $github_app_id)
                ->where('team_id', $teamId)
                ->firstOrFail();

            // Define allowed fields for update
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
            ];

            if (! isCloud()) {
                $allowedFields[] = 'is_system_wide';
            }

            $payload = $request->only($allowedFields);

            // Validate the request
            $rules = [];
            if (isset($payload['name'])) {
                $rules['name'] = 'string';
            }
            if (isset($payload['organization'])) {
                $rules['organization'] = 'nullable|string';
            }
            if (isset($payload['api_url'])) {
                $rules['api_url'] = 'url';
            }
            if (isset($payload['html_url'])) {
                $rules['html_url'] = 'url';
            }
            if (isset($payload['custom_user'])) {
                $rules['custom_user'] = 'string';
            }
            if (isset($payload['custom_port'])) {
                $rules['custom_port'] = 'integer|min:1|max:65535';
            }
            if (isset($payload['app_id'])) {
                $rules['app_id'] = 'integer';
            }
            if (isset($payload['installation_id'])) {
                $rules['installation_id'] = 'integer';
            }
            if (isset($payload['client_id'])) {
                $rules['client_id'] = 'string';
            }
            if (isset($payload['client_secret'])) {
                $rules['client_secret'] = 'string';
            }
            if (isset($payload['webhook_secret'])) {
                $rules['webhook_secret'] = 'string';
            }
            if (isset($payload['private_key_uuid'])) {
                $rules['private_key_uuid'] = 'string|uuid';
            }
            if (! isCloud() && isset($payload['is_system_wide'])) {
                $rules['is_system_wide'] = 'boolean';
            }

            $validator = customApiValidator($payload, $rules);
            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Handle private_key_uuid -> private_key_id conversion
            if (isset($payload['private_key_uuid'])) {
                $privateKey = PrivateKey::where('team_id', $teamId)
                    ->where('uuid', $payload['private_key_uuid'])
                    ->first();

                if (! $privateKey) {
                    return response()->json([
                        'message' => 'Private key not found or does not belong to your team',
                    ], 404);
                }

                unset($payload['private_key_uuid']);
                $payload['private_key_id'] = $privateKey->id;
            }

            // Update the GitHub app
            $githubApp->update($payload);

            return response()->json([
                'message' => 'GitHub app updated successfully',
                'data' => $githubApp,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'GitHub app not found',
            ], 404);
        }
    }

    /**
     * Delete a GitHub app.
     */
    #[OA\Delete(
        path: '/github-apps/{github_app_id}',
        operationId: 'deleteGithubApp',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['GitHub Apps'],
        summary: 'Delete GitHub App',
        description: 'Delete a GitHub app if it\'s not being used by any applications.',
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
                description: 'GitHub app deleted successfully',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'message' => ['type' => 'string', 'example' => 'GitHub app deleted successfully'],
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'GitHub app not found'),
            new OA\Response(
                response: 409,
                description: 'Conflict - GitHub app is in use',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'message' => ['type' => 'string', 'example' => 'This GitHub app is being used by 5 application(s). Please delete all applications first.'],
                        ]
                    )
                )
            ),
        ]
    )]
    public function delete_github_app($github_app_id)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        try {
            $githubApp = GithubApp::where('id', $github_app_id)
                ->where('team_id', $teamId)
                ->firstOrFail();

            // Check if the GitHub app is being used by any applications
            if ($githubApp->applications->isNotEmpty()) {
                $count = $githubApp->applications->count();

                return response()->json([
                    'message' => "This GitHub app is being used by {$count} application(s). Please delete all applications first.",
                ], 409);
            }

            $githubApp->delete();

            return response()->json([
                'message' => 'GitHub app deleted successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'GitHub app not found',
            ], 404);
        }
    }
}
