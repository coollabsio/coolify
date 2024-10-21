<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use App\Models\GithubApp;
use App\Models\Application;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Models\Project;
use Illuminate\Support\Facades\Http;

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
                            'api_url' => ['type' => 'string', 'description' => 'API URL for the GitHub app.'],
                            'html_url' => ['type' => 'string', 'description' => 'HTML URL for the GitHub app.'],
                            'custom_user' => ['type' => 'string', 'description' => 'Custom user for the app.'],
                            'custom_port' => ['type' => 'integer', 'description' => 'Custom port for the app.'],
                            'is_system_wide' => ['type' => 'boolean', 'description' => 'Is this app system-wide.'],
                        ],
                        required: ['name', 'api_url', 'html_url', 'custom_user', 'custom_port'],
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
        ]
    )]

    public function createGitHubApp(Request $request){
    // Extract the team ID from the current session or token
    $teamId = currentTeam()->id;

    try {
        

        $validator = customApiValidator($request->all(), [
            'name' => 'required|string|max:255',
            'organization' => 'nullable|string|max:255',
            'api_url' => 'required|string|url',
            'html_url' => 'required|string|url',
            'custom_user' => 'required|string|max:255',
            'custom_port' => 'required|integer',
            'is_system_wide' => 'required|boolean',
            
        ]);

        // If validation fails, return a 400 error with validation messages
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 400);
        }

        // Prepare the payload for creating the GitHub app
        $payload = [
            'name' => $request->input('name'),
            'organization' => $request->input('organization'),
            'api_url' => $request->input('api_url'),
            'html_url' => $request->input('html_url'),
            'custom_user' => $request->input('custom_user'),
            'custom_port' => $request->input('custom_port'),
            'team_id' => $teamId,
        ];

        // If running in cloud environment, include 'is_system_wide'
        if (isCloud()) {
            $payload['is_system_wide'] = $request->input('is_system_wide');
        }

        // Create the GitHub app in the database
        $githubApp = GithubApp::create($payload);

        // Return the newly created GitHub app with a 201 status
        return response()->json([
            'message' => 'GitHub app created successfully.',
            'data' => $githubApp,
        ], 201);

    } catch (\Throwable $e) {
        // Handle any errors that occur during the process
        return response()->json([
            'message' => 'An error occurred while creating the GitHub app.',
            'error' => $e->getMessage(),
        ], 500);
    }
    }

    #[OA\Get(
        path: '/github-apps/{github_app_id}/repositories',
        summary: 'Load Repositories for a GitHub App',
        description: 'Fetch repositories from GitHub for a given GitHub app.',
        operationId: 'load-repositories',
        tags: ['GitHub Repositories'],
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
            )
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
                            )
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
            )
        ]
    )]
    public function loadRepositories($github_app_id)
    {
        try {
            // Fetch GitHub app by ID
            $githubApp = GithubApp::findOrFail($github_app_id);
            $token = generate_github_installation_token($githubApp);
            $repositories = collect();
            $page = 1;

            // Fetch repositories in a loop to handle pagination
            do {
                $response = Http::withToken($token)->get("{$githubApp->api_url}/installation/repositories", [
                    'per_page' => 100,
                    'page' => $page
                ]);

                if ($response->status() !== 200) {
                    return response()->json([
                        'message' => $response->json()['message'],
                    ], $response->status());
                }

                $json = $response->json();
                $repositories = $repositories->concat($json['repositories']);
                $page++;
            } while (count($json['repositories']) > 0);

            return response()->json([
                'message' => 'Repositories loaded successfully.',
                'repositories' => $repositories->sortBy('name')->values(),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'An error occurred while loading repositories.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    #[OA\Get(
        path: '/github-apps/{github_app_id}/repositories/{owner}/{repo}/branches',
        summary: 'Load Branches for a GitHub Repository',
        description: 'Fetch branches from GitHub for a given repository.',
        operationId: 'load-branches',
        tags: ['GitHub Branches'],
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
                            )
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
        ]
    )]
    public function loadBranches($github_app_id, $owner, $repo)
    {
        try {
            // Fetch the GitHub App
            $githubApp = GithubApp::findOrFail($github_app_id);
            $token = generate_github_installation_token($githubApp);

            // API call to GitHub to load branches
            $response = Http::withToken($token)->get("{$githubApp->api_url}/repos/{$owner}/{$repo}/branches");

            // Handle the response from GitHub API
            if ($response->status() !== 200) {
                return response()->json([
                    'message' => 'Error loading branches from GitHub.',
                    'error' => $response->json('message')
                ], $response->status());
            }

            $branches = $response->json();

            return response()->json([
                'message' => 'Branches loaded successfully.',
                'branches' => $branches,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'An error occurred while loading branches.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
 
    
}