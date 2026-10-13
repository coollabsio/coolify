<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GitlabApp;
use App\Rules\SafeExternalUrl;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class GitlabController extends Controller
{
    private function removeSensitiveData(GitlabApp $gitlabApp, int $teamId)
    {
        if (request()->attributes->get('can_read_sensitive', false) === true && $gitlabApp->team_id === $teamId) {
            $gitlabApp->makeVisible([
                'client_secret',
                'webhook_token',
                'access_token',
                'refresh_token',
            ]);
        } else {
            $gitlabApp->makeHidden([
                'client_secret',
                'webhook_token',
                'access_token',
                'refresh_token',
            ]);
        }

        return serializeApiResponse($gitlabApp);
    }

    private function findTeamGitlabApp(int|string $gitlabAppId, int $teamId): GitlabApp
    {
        return GitlabApp::where('id', $gitlabAppId)
            ->where('team_id', $teamId)
            ->firstOrFail();
    }

    private function gitlabApiUrlFromHtmlUrl(string $htmlUrl): string
    {
        return rtrim($htmlUrl, '/').'/api/v4';
    }

    #[OA\Get(
        summary: 'List',
        description: 'List all GitLab apps for the current team (and system-wide sources).',
        path: '/gitlab-apps',
        operationId: 'list-gitlab-apps',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['GitLab Apps'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of GitLab apps.',
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
                                    'api_url' => ['type' => 'string'],
                                    'html_url' => ['type' => 'string'],
                                    'custom_user' => ['type' => 'string'],
                                    'custom_port' => ['type' => 'integer'],
                                    'client_id' => ['type' => 'string', 'nullable' => true],
                                    'group_name' => ['type' => 'string', 'nullable' => true],
                                    'redirect_uri' => ['type' => 'string', 'nullable' => true],
                                    'is_system_wide' => ['type' => 'boolean'],
                                    'is_public' => ['type' => 'boolean'],
                                    'team_id' => ['type' => 'integer'],
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
    public function list_gitlab_apps(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $gitlabApps = GitlabApp::where(function ($query) use ($teamId) {
            $query->where('team_id', $teamId)
                ->orWhere('is_system_wide', true);
        })->get();

        $gitlabApps = $gitlabApps->map(function ($app) use ($teamId) {
            return $this->removeSensitiveData($app, $teamId);
        });

        return response()->json($gitlabApps);
    }

    #[OA\Post(
        summary: 'Create GitLab App',
        description: 'Create a new GitLab app (OAuth source). Credentials may be supplied later via the UI or update endpoint.',
        path: '/gitlab-apps',
        operationId: 'create-gitlab-app',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['GitLab Apps'],
        requestBody: new OA\RequestBody(
            description: 'GitLab app creation payload.',
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'name' => ['type' => 'string', 'description' => 'Name of the GitLab app.'],
                            'html_url' => ['type' => 'string', 'description' => 'GitLab instance URL (e.g., https://gitlab.com).'],
                            'api_url' => ['type' => 'string', 'description' => 'GitLab API URL (defaults to {html_url}/api/v4).'],
                            'custom_user' => ['type' => 'string', 'description' => 'Custom user for SSH access (default: git).'],
                            'custom_port' => ['type' => 'integer', 'description' => 'Custom port for SSH access (default: 22).'],
                            'group_name' => ['type' => 'string', 'nullable' => true, 'description' => 'Optional comma-separated group names to filter repositories.'],
                            'client_id' => ['type' => 'string', 'nullable' => true, 'description' => 'GitLab OAuth Application ID.'],
                            'client_secret' => ['type' => 'string', 'nullable' => true, 'description' => 'GitLab OAuth Application Secret.'],
                            'webhook_token' => ['type' => 'string', 'nullable' => true, 'description' => 'Webhook secret token (auto-generated when omitted).'],
                            'redirect_uri' => ['type' => 'string', 'nullable' => true, 'description' => 'OAuth redirect URI registered in GitLab.'],
                            'is_system_wide' => ['type' => 'boolean', 'description' => 'Is this app system-wide (non-cloud instances only).'],
                        ],
                        required: ['name', 'html_url'],
                    ),
                ),
            ],
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'GitLab app created successfully.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'id' => ['type' => 'integer'],
                                'uuid' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'api_url' => ['type' => 'string'],
                                'html_url' => ['type' => 'string'],
                                'custom_user' => ['type' => 'string'],
                                'custom_port' => ['type' => 'integer'],
                                'client_id' => ['type' => 'string', 'nullable' => true],
                                'group_name' => ['type' => 'string', 'nullable' => true],
                                'redirect_uri' => ['type' => 'string', 'nullable' => true],
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
    public function create_gitlab_app(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $this->authorize('create', [GitlabApp::class]);
        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $allowedFields = [
            'name',
            'html_url',
            'api_url',
            'custom_user',
            'custom_port',
            'group_name',
            'client_id',
            'client_secret',
            'webhook_token',
            'redirect_uri',
            'is_system_wide',
        ];

        $validator = customApiValidator($request->all(), [
            'name' => 'required|string|max:255',
            'html_url' => ['required', 'string', 'url', new SafeExternalUrl],
            'api_url' => ['nullable', 'string', 'url', new SafeExternalUrl],
            'custom_user' => 'nullable|string|max:255',
            'custom_port' => 'nullable|integer|min:1|max:65535',
            'group_name' => 'nullable|string|max:255',
            'client_id' => 'nullable|string|max:255',
            'client_secret' => 'nullable|string',
            'webhook_token' => 'nullable|string',
            // Callback to this Coolify instance — may be a private/LAN URL; do not use SafeExternalUrl.
            'redirect_uri' => ['nullable', 'string', 'url'],
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
            $htmlUrl = rtrim((string) $request->input('html_url'), '/');
            $apiUrl = filled($request->input('api_url'))
                ? rtrim((string) $request->input('api_url'), '/')
                : $this->gitlabApiUrlFromHtmlUrl($htmlUrl);

            $payload = [
                'name' => $request->input('name'),
                'html_url' => $htmlUrl,
                'api_url' => $apiUrl,
                'custom_user' => $request->input('custom_user', 'git'),
                'custom_port' => $request->input('custom_port', 22),
                'group_name' => $request->input('group_name'),
                'client_id' => $request->input('client_id'),
                'client_secret' => $request->input('client_secret'),
                'webhook_token' => $request->input('webhook_token') ?: Str::random(32),
                'redirect_uri' => $request->input('redirect_uri'),
                'is_public' => false,
                'team_id' => $teamId,
            ];

            if (! isCloud()) {
                $payload['is_system_wide'] = $request->boolean('is_system_wide', false);
            }

            $gitlabApp = GitlabApp::create($payload);

            auditLog('api.gitlab_app.created', [
                'team_id' => $teamId,
                'gitlab_app_uuid' => $gitlabApp->uuid,
                'gitlab_app_name' => $gitlabApp->name,
            ]);

            return response()->json($this->removeSensitiveData($gitlabApp->fresh(), $teamId), 201);
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }

    #[OA\Patch(
        path: '/gitlab-apps/{gitlab_app_id}',
        operationId: 'updateGitlabApp',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['GitLab Apps'],
        summary: 'Update GitLab App',
        description: 'Update an existing GitLab app.',
        parameters: [
            new OA\Parameter(
                name: 'gitlab_app_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'GitLab App ID'
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => ['type' => 'string', 'description' => 'GitLab App name'],
                        'html_url' => ['type' => 'string', 'description' => 'GitLab HTML URL'],
                        'api_url' => ['type' => 'string', 'description' => 'GitLab API URL'],
                        'custom_user' => ['type' => 'string', 'description' => 'Custom user for SSH'],
                        'custom_port' => ['type' => 'integer', 'description' => 'Custom port for SSH'],
                        'group_name' => ['type' => 'string', 'nullable' => true, 'description' => 'Optional group filter'],
                        'client_id' => ['type' => 'string', 'nullable' => true, 'description' => 'OAuth Application ID'],
                        'client_secret' => ['type' => 'string', 'nullable' => true, 'description' => 'OAuth Application Secret'],
                        'webhook_token' => ['type' => 'string', 'nullable' => true, 'description' => 'Webhook secret token'],
                        'redirect_uri' => ['type' => 'string', 'nullable' => true, 'description' => 'OAuth redirect URI'],
                        'is_system_wide' => ['type' => 'boolean', 'description' => 'Is system wide (non-cloud instances only)'],
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'GitLab app updated successfully',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'message' => ['type' => 'string', 'example' => 'GitLab app updated successfully'],
                            'data' => ['type' => 'object', 'description' => 'Updated GitLab app data'],
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'GitLab app not found'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update_gitlab_app(Request $request, $gitlab_app_id)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        try {
            $gitlabApp = $this->findTeamGitlabApp($gitlab_app_id, $teamId);
            $this->authorize('update', $gitlabApp);

            $allowedFields = [
                'name',
                'html_url',
                'api_url',
                'custom_user',
                'custom_port',
                'group_name',
                'client_id',
                'client_secret',
                'webhook_token',
                'redirect_uri',
            ];

            if (! isCloud()) {
                $allowedFields[] = 'is_system_wide';
            }

            $payload = $request->only($allowedFields);

            $rules = [];
            if (isset($payload['name'])) {
                $rules['name'] = 'string|max:255';
            }
            if (isset($payload['html_url'])) {
                $rules['html_url'] = ['url', new SafeExternalUrl];
            }
            if (isset($payload['api_url'])) {
                $rules['api_url'] = ['url', new SafeExternalUrl];
            }
            if (isset($payload['custom_user'])) {
                $rules['custom_user'] = 'string|max:255';
            }
            if (isset($payload['custom_port'])) {
                $rules['custom_port'] = 'integer|min:1|max:65535';
            }
            if (array_key_exists('group_name', $payload)) {
                $rules['group_name'] = 'nullable|string|max:255';
            }
            if (array_key_exists('client_id', $payload)) {
                $rules['client_id'] = 'nullable|string|max:255';
            }
            if (array_key_exists('client_secret', $payload)) {
                $rules['client_secret'] = 'nullable|string';
            }
            if (array_key_exists('webhook_token', $payload)) {
                $rules['webhook_token'] = 'nullable|string';
            }
            if (array_key_exists('redirect_uri', $payload)) {
                // Callback to this Coolify instance — may be a private/LAN URL.
                $rules['redirect_uri'] = 'nullable|url';
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

            if (isset($payload['html_url'])) {
                $payload['html_url'] = rtrim((string) $payload['html_url'], '/');
                if (! filled($payload['api_url'] ?? null)) {
                    $payload['api_url'] = $this->gitlabApiUrlFromHtmlUrl($payload['html_url']);
                }
            }
            if (isset($payload['api_url'])) {
                $payload['api_url'] = rtrim((string) $payload['api_url'], '/');
            }

            $gitlabApp->update($payload);

            auditLog('api.gitlab_app.updated', [
                'team_id' => $teamId,
                'gitlab_app_uuid' => $gitlabApp->uuid,
                'gitlab_app_name' => $gitlabApp->name,
                'changed_fields' => array_values(array_diff(array_keys($payload), ['client_secret', 'webhook_token'])),
            ]);

            return response()->json([
                'message' => 'GitLab app updated successfully',
                'data' => $this->removeSensitiveData($gitlabApp->fresh(), $teamId),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'GitLab app not found',
            ], 404);
        }
    }

    #[OA\Delete(
        path: '/gitlab-apps/{gitlab_app_id}',
        operationId: 'deleteGitlabApp',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['GitLab Apps'],
        summary: 'Delete GitLab App',
        description: 'Delete a GitLab app if it is not being used by any applications.',
        parameters: [
            new OA\Parameter(
                name: 'gitlab_app_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'GitLab App ID'
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'GitLab app deleted successfully',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'message' => ['type' => 'string', 'example' => 'GitLab app deleted successfully'],
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'GitLab app not found'),
            new OA\Response(
                response: 409,
                description: 'Conflict - GitLab app is in use',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'message' => ['type' => 'string', 'example' => 'This GitLab app is being used by 5 application(s). Please delete all applications first.'],
                        ]
                    )
                )
            ),
        ]
    )]
    public function delete_gitlab_app($gitlab_app_id)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        try {
            $gitlabApp = $this->findTeamGitlabApp($gitlab_app_id, $teamId);
            $this->authorize('delete', $gitlabApp);

            if ($gitlabApp->applications->isNotEmpty()) {
                $count = $gitlabApp->applications->count();

                return response()->json([
                    'message' => "This GitLab app is being used by {$count} application(s). Please delete all applications first.",
                ], 409);
            }

            $deletedUuid = $gitlabApp->uuid;
            $deletedName = $gitlabApp->name;
            $gitlabApp->delete();

            auditLog('api.gitlab_app.deleted', [
                'team_id' => $teamId,
                'gitlab_app_uuid' => $deletedUuid,
                'gitlab_app_name' => $deletedName,
            ]);

            return response()->json([
                'message' => 'GitLab app deleted successfully',
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'GitLab app not found',
            ], 404);
        }
    }
}
