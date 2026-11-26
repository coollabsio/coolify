<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConfigRevisionStatus;
use App\Http\Controllers\Controller;
use App\Jobs\PlanEnvironmentConfigJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Rules\ValidGitBranch;
use App\Services\ProjectConfig\AdapterRegistry;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\ConfigException;
use App\Services\ProjectConfig\ConfigExporter;
use App\Services\ProjectConfig\ConfigLinks;
use App\Services\ProjectConfig\ConfigPlan;
use App\Services\ProjectConfig\ConfigPlanner;
use App\Services\ProjectConfig\ConfigPlanView;
use App\Services\ProjectConfig\ConfigRevisions;
use App\Services\ProjectConfig\ConfigTemplate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class ProjectConfigController extends Controller
{
    #[OA\Get(
        summary: 'Export coolify.json',
        description: 'Export an environment as a coolify.json file. Variable values are never exported. Requires the `read:sensitive` or `root` token ability, because commands and Compose files can hold credentials.',
        path: '/environments/{uuid}/config/export',
        operationId: 'export-environment-config',
        security: [['bearerAuth' => []]],
        tags: ['Project config'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The coolify.json file.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token cannot read sensitive data.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function export(Request $request, string $uuid, ConfigExporter $exporter): JsonResponse
    {
        $environment = $this->environment($uuid);
        if ($environment instanceof JsonResponse) {
            return $environment;
        }

        if (! self::canReadSensitive()) {
            return response()->json(['message' => 'Exporting needs the read:sensitive ability.'], 403);
        }

        return response()->json($exporter->export($environment));
    }

    #[OA\Get(
        summary: 'Export application as coolify.json',
        description: 'Export one application as a coolify.json file for its environment. Variable values are never exported. Requires the `read:sensitive` or `root` token ability, because commands and Compose files can hold credentials.',
        path: '/applications/{uuid}/config/export',
        operationId: 'export-application-config',
        security: [['bearerAuth' => []]],
        tags: ['Project config'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The coolify.json file.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token cannot read sensitive data.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function exportApplication(string $uuid, ConfigExporter $exporter): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first();
        if ($application === null || ! auth()->user()->can('view', $application)) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        if (! self::canReadSensitive()) {
            return response()->json(['message' => 'Exporting needs the read:sensitive ability.'], 403);
        }

        return response()->json($exporter->export($application->environment, $application));
    }

    #[OA\Post(
        summary: 'Plan coolify.json',
        description: 'Compare a coolify.json file with the environment. Nothing changes.',
        path: '/environments/{uuid}/config/plan',
        operationId: 'plan-environment-config',
        security: [['bearerAuth' => []]],
        tags: ['Project config'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['file'],
            properties: [new OA\Property(property: 'file', type: 'string', description: 'The coolify.json file as text.')],
            type: 'object',
        )),
        responses: [
            new OA\Response(response: 200, description: 'The plan.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function plan(Request $request, string $uuid, ConfigRevisions $revisions, ConfigPlanner $planner): JsonResponse
    {
        $environment = $this->environment($uuid);
        if ($environment instanceof JsonResponse) {
            return $environment;
        }

        $validator = Validator::make($request->all(), ['file' => 'present|string']);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $link = ConfigExporter::linkFor($environment);
        try {
            $resolved = $revisions->resolve($link, $request->input('file'));
        } catch (ConfigException $e) {
            return response()->json([
                'message' => 'The file is not valid.',
                'errors' => array_map(fn ($error) => $error->toArray(), $e->errors()),
            ], 422);
        }

        $plan = $planner->plan($resolved, ApplyContext::for($link, $request->user()))->toArray();

        return response()->json(self::canReadSensitive() ? $plan : ConfigPlanView::withoutValues($plan));
    }

    #[OA\Post(
        summary: 'Plan coolify.json as a template',
        description: 'Read coolify.json from a repository and plan the resources that a template creates in the environment. Nothing changes. Needs the right to create resources in the environment. A git source or deploy key needs write access to the whole team.',
        path: '/environments/{uuid}/config/template/plan',
        operationId: 'plan-environment-config-template',
        security: [['bearerAuth' => []]],
        tags: ['Project config'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['repository', 'branch', 'source_type', 'server_uuid'],
            properties: [
                new OA\Property(property: 'repository', type: 'string', description: 'The repository: a URL, or owner/name for a git app.'),
                new OA\Property(property: 'branch', type: 'string'),
                new OA\Property(property: 'file_path', type: 'string', description: 'The default is coolify.json.'),
                new OA\Property(property: 'source_type', type: 'string', enum: ['public', 'github_app', 'gitlab_app', 'gitea_app', 'deploy_key']),
                new OA\Property(property: 'source_uuid', type: 'string', description: 'The UUID of the git app or the private key.'),
                new OA\Property(property: 'server_uuid', type: 'string', description: 'The server that reads the file. Resources that name no server run on it.'),
                new OA\Property(property: 'destination_uuid', type: 'string', description: 'Needed only when the server has more than one destination.'),
            ],
            type: 'object',
        )),
        responses: [
            new OA\Response(response: 200, description: 'The plan. Its commit goes to the create request.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not allowed.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, description: 'The request or the file is not valid.'),
        ]
    )]
    public function templatePlan(Request $request, string $uuid, ConfigLinks $links, ConfigTemplate $template): JsonResponse
    {
        $link = $this->templateLink($request, $uuid, $links);
        if ($link instanceof JsonResponse) {
            return $link;
        }

        $result = $template->plan($link, $request->user());
        if ($result['plan'] === null) {
            return response()->json(['message' => $result['error'], 'errors' => $result['errors']], 422);
        }

        return response()->json(self::canReadSensitive() ? $result['plan'] : ConfigPlanView::withoutValues($result['plan']));
    }

    #[OA\Post(
        summary: 'Create resources from coolify.json',
        description: 'Create the resources of coolify.json in the environment one time. They are normal resources: the file does not manage them. Nothing deploys. Needs the right to create resources in the environment.',
        path: '/environments/{uuid}/config/template',
        operationId: 'create-from-environment-config-template',
        security: [['bearerAuth' => []]],
        tags: ['Project config'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['repository', 'branch', 'source_type', 'server_uuid'],
            properties: [
                new OA\Property(property: 'repository', type: 'string'),
                new OA\Property(property: 'branch', type: 'string'),
                new OA\Property(property: 'file_path', type: 'string'),
                new OA\Property(property: 'source_type', type: 'string', enum: ['public', 'github_app', 'gitlab_app', 'gitea_app', 'deploy_key']),
                new OA\Property(property: 'source_uuid', type: 'string'),
                new OA\Property(property: 'server_uuid', type: 'string'),
                new OA\Property(property: 'destination_uuid', type: 'string'),
                new OA\Property(property: 'commit', type: 'string', description: 'The commit of the plan. Coolify creates the resources from the file at this commit. Without it, the head of the branch.'),
            ],
            type: 'object',
        )),
        responses: [
            new OA\Response(response: 201, description: 'The created resources.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'resources', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'uuid', type: 'string'),
                    new OA\Property(property: 'type', type: 'string'),
                    new OA\Property(property: 'name', type: 'string'),
                ], type: 'object')),
            ], type: 'object')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not allowed.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, description: 'The request or the file is not valid.'),
        ]
    )]
    public function templateCreate(Request $request, string $uuid, ConfigLinks $links, ConfigTemplate $template, AdapterRegistry $registry): JsonResponse
    {
        $link = $this->templateLink($request, $uuid, $links, ['commit' => ['nullable', 'string', 'regex:/^[0-9a-f]{40,64}\z/']]);
        if ($link instanceof JsonResponse) {
            return $link;
        }

        try {
            $created = $template->create($link, $request->user(), $request->input('commit'));
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (ConfigException $e) {
            return response()->json(['message' => 'The file is not valid.', 'errors' => array_map(fn ($error) => $error->toArray(), $e->errors())], 422);
        } catch (\PDOException $e) {
            // A database error is a RuntimeException too, and its message has the SQL.
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['resources' => array_map(fn ($model) => [
            'uuid' => $model->uuid,
            'type' => $registry->forModel($model)->type(),
            'name' => $model->name,
        ], $created)], 201);
    }

    /**
     * The unsaved git link of a template request, or the error response.
     *
     * @param  array<string, mixed>  $extraRules
     */
    private function templateLink(Request $request, string $uuid, ConfigLinks $links, array $extraRules = []): EnvironmentConfigLink|JsonResponse
    {
        $environment = $this->environment($uuid);
        if ($environment instanceof JsonResponse) {
            return $environment;
        }
        $validator = Validator::make($request->all(), [
            'repository' => 'required|string|max:500',
            'branch' => ['required', 'string', new ValidGitBranch],
            'file_path' => ['nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9._/-]+\z#', 'not_regex:#(^|/)\.\.(/|\z)#'],
            'source_type' => 'required|in:public,github_app,gitlab_app,gitea_app,deploy_key',
            'source_uuid' => 'required_unless:source_type,public|nullable|string',
            'server_uuid' => 'required|string',
            'destination_uuid' => 'nullable|string',
            ...$extraRules,
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        try {
            $link = $links->unsavedGitLink($request->user(), $environment, $validator->safe()->except(array_keys($extraRules)));
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return $link;
    }

    #[OA\Post(summary: 'Link coolify.json', description: 'Link an environment to coolify.json. Creates a missing project or environment.', path: '/config/links', operationId: 'create-config-link', security: [['bearerAuth' => []]], tags: ['Project config'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['project', 'environment', 'mode'], properties: [
            new OA\Property(property: 'project', type: 'string'),
            new OA\Property(property: 'environment', type: 'string'),
            new OA\Property(property: 'mode', type: 'string', enum: ['api', 'git']),
            new OA\Property(property: 'auto_apply', type: 'boolean'),
            new OA\Property(property: 'server_uuid', type: 'string', description: 'Required for git. Reads the file, and resources that name no server go to it.'),
            new OA\Property(property: 'destination_uuid', type: 'string', description: 'The destination on that server. Needed when the server has more than one.'),
        ], type: 'object')),
        responses: [new OA\Response(response: 201, description: 'The link.'), new OA\Response(response: 403, description: 'Not allowed.'), new OA\Response(response: 409, description: 'The environment already has a link.')]
    )]
    public function createLink(Request $request, ConfigLinks $links): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $validator = Validator::make($request->all(), [
            'project' => 'required|string|max:255',
            'environment' => 'required|string|max:255',
            'mode' => 'required|in:api,git',
            'auto_apply' => 'boolean',
            'repository' => 'required_if:mode,git|string|max:500',
            'branch' => ['required_if:mode,git', 'string', new ValidGitBranch],
            'file_path' => ['nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9._/-]+\z#', 'not_regex:#(^|/)\.\.(/|\z)#'],
            'source_type' => 'required_if:mode,git|in:public,github_app,gitlab_app,gitea_app,deploy_key',
            'source_uuid' => 'required_if:source_type,github_app,gitlab_app,gitea_app,deploy_key|nullable|string',
            'server_uuid' => 'required_if:mode,git|nullable|string',
            'destination_uuid' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        try {
            $link = $links->create($request->user(), (int) $teamId, $validator->validated());
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\PDOException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() === 409 ? 409 : 400);
        }

        $body = self::serializeLink($link->fresh());
        if ($link->isGit()) {
            PlanEnvironmentConfigJob::dispatch($link->fresh(), 'link');
            $body += self::webhookDetails($link->fresh());
        }

        return response()->json($body, 201);
    }

    #[OA\Get(summary: 'Get a coolify.json link', path: '/config/links/{uuid}', operationId: 'get-config-link', security: [['bearerAuth' => []]], tags: ['Project config'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'The link.'), new OA\Response(response: 404, ref: '#/components/responses/404')]
    )]
    public function showLink(string $uuid): JsonResponse
    {
        $link = $this->findLink($uuid);

        return $link instanceof JsonResponse ? $link : response()->json(self::serializeLink($link));
    }

    #[OA\Patch(summary: 'Update a coolify.json link', path: '/config/links/{uuid}', operationId: 'update-config-link', security: [['bearerAuth' => []]], tags: ['Project config'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'auto_apply', type: 'boolean'),
            new OA\Property(property: 'server_uuid', type: 'string', description: 'Another server for the link. Plans the file again; resources that move wait for approval. Needs the deploy ability.'),
            new OA\Property(property: 'destination_uuid', type: 'string', description: 'The destination on that server. Needed when the server has more than one.'),
        ], type: 'object')),
        responses: [new OA\Response(response: 200, description: 'The link.'), new OA\Response(response: 403, description: 'Team admins only, and the deploy ability for a server change.'), new OA\Response(response: 422, description: 'Validation failed.')]
    )]
    public function updateLink(Request $request, string $uuid, ConfigLinks $links): JsonResponse
    {
        $link = $this->findLink($uuid);
        if ($link instanceof JsonResponse) {
            return $link;
        }
        $validator = Validator::make($request->all(), [
            'auto_apply' => 'required_without:server_uuid|boolean',
            'server_uuid' => 'nullable|string',
            'destination_uuid' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }
        $changesServer = $request->filled('server_uuid');
        // A server change plans the file again and can apply safe changes, like /apply.
        if ($changesServer && ! $request->user()->tokenCan('root') && ! $request->user()->tokenCan('deploy')) {
            return response()->json(['message' => 'Changing the server needs the deploy ability.'], 403);
        }
        try {
            if ($request->has('auto_apply')) {
                $links->setAutoApply($link, $request->user(), $request->boolean('auto_apply'));
            }
            if ($changesServer) {
                $links->changeServer($link, $request->user(), $request->only(['server_uuid', 'destination_uuid']));
            }
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        }

        return response()->json(self::serializeLink($link->fresh()));
    }

    #[OA\Delete(summary: 'Disconnect coolify.json', description: 'Removes the link. The resources stay and can be edited in Coolify again.', path: '/config/links/{uuid}', operationId: 'delete-config-link', security: [['bearerAuth' => []]], tags: ['Project config'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Disconnected.'), new OA\Response(response: 403, description: 'Team admins only.')]
    )]
    public function deleteLink(Request $request, string $uuid, ConfigLinks $links): JsonResponse
    {
        $link = $this->findLink($uuid);
        if ($link instanceof JsonResponse) {
            return $link;
        }
        try {
            $links->disconnect($link, $request->user());
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['message' => 'Disconnected.']);
    }

    #[OA\Get(summary: 'List revisions', path: '/config/links/{uuid}/revisions', operationId: 'list-config-revisions', security: [['bearerAuth' => []]], tags: ['Project config'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Revisions, newest first.'), new OA\Response(response: 404, ref: '#/components/responses/404')]
    )]
    public function revisions(string $uuid): JsonResponse
    {
        $link = $this->findLink($uuid);
        if ($link instanceof JsonResponse) {
            return $link;
        }

        return response()->json($link->revisions()->latest('id')->limit(50)->get()->map(self::serializeRevision(...)));
    }

    #[OA\Post(summary: 'Apply coolify.json', description: 'In api mode, plan the posted file and apply it when the plan is safe.', path: '/config/links/{uuid}/apply', operationId: 'apply-config', security: [['bearerAuth' => []]], tags: ['Project config'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'file', type: 'string')], type: 'object')),
        responses: [new OA\Response(response: 200, description: 'The revision waits for approval.'), new OA\Response(response: 202, description: 'The revision is applied or queued.'), new OA\Response(response: 409, description: 'The link is suspended.'), new OA\Response(response: 422, description: 'The file is not valid.')]
    )]
    public function apply(Request $request, string $uuid, ConfigLinks $links): JsonResponse
    {
        $link = $this->findLink($uuid);
        if ($link instanceof JsonResponse) {
            return $link;
        }
        if (! $request->user()->can('update', $link->environment)) {
            return response()->json(['message' => 'You cannot change this environment.'], 403);
        }
        if ($link->isGit()) {
            if ($request->has('file')) {
                return response()->json(['message' => 'This environment follows its branch. Push the file instead.'], 422);
            }
            PlanEnvironmentConfigJob::dispatchSync($link, 'api');
            $revision = $link->revisions()->latest('id')->first();

            return response()->json(self::serializeRevision($revision->fresh()), $revision->status === ConfigRevisionStatus::Failed ? 422 : 202);
        }
        if ($link->isSuspended()) {
            return response()->json(['message' => 'This link is suspended. Resume it first.'], 409);
        }
        $validator = Validator::make($request->all(), ['file' => 'present|string']);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $revision = $links->applyFile($link, $request->input('file'), $request->user());

        return response()->json(self::serializeRevision($revision), match ($revision->status) {
            ConfigRevisionStatus::Failed => 422,
            ConfigRevisionStatus::WaitingApproval => 200,
            default => 202,
        });
    }

    #[OA\Post(summary: 'Approve a plan', path: '/config/revisions/{uuid}/approve', operationId: 'approve-config-revision', security: [['bearerAuth' => []]], tags: ['Project config'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'delete_data', type: 'array', items: new OA\Items(type: 'string'))], type: 'object')),
        responses: [new OA\Response(response: 200, description: 'Approved.'), new OA\Response(response: 403, description: 'Not allowed.'), new OA\Response(response: 422, description: 'Not waiting, or a key is not removed.')]
    )]
    public function approve(Request $request, string $uuid, ConfigLinks $links): JsonResponse
    {
        $revision = $this->revision($uuid);
        if ($revision instanceof JsonResponse) {
            return $revision;
        }
        if (! $request->user()->can('delete', $revision->link->environment)) {
            return response()->json(['message' => 'Approving needs delete access to the environment.'], 403);
        }
        if ($revision->status !== ConfigRevisionStatus::WaitingApproval) {
            return response()->json(['message' => 'This revision does not wait for approval.'], 422);
        }
        $validator = Validator::make($request->all(), ['delete_data' => 'array', 'delete_data.*' => 'string']);
        $removed = collect($revision->plan['changes'] ?? [])->where('kind', 'remove')->pluck('key')->all();
        $deleteData = (array) $request->input('delete_data', []);
        if ($validator->fails() || array_diff($deleteData, $removed) !== []) {
            return response()->json(['message' => 'Validation failed.', 'errors' => ['delete_data' => ['Use only keys of resources that this plan removes.']]], 422);
        }

        return response()->json(self::serializeRevision($links->approve($revision, $request->user(), $deleteData)->fresh()));
    }

    #[OA\Post(summary: 'Suspend a link', path: '/config/links/{uuid}/suspend', operationId: 'suspend-config-link', security: [['bearerAuth' => []]], tags: ['Project config'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Suspended.'), new OA\Response(response: 403, description: 'Team admins only.')]
    )]
    public function suspend(Request $request, string $uuid, ConfigLinks $links): JsonResponse
    {
        $link = $this->adminLink($request, $uuid);
        if ($link instanceof JsonResponse) {
            return $link;
        }
        $links->suspend($link, $request->user());

        return response()->json(self::serializeLink($link->fresh()));
    }

    #[OA\Post(summary: 'Resume a link', description: 'Without confirm=1, returns the plan of the latest file. With confirm=1, resumes and applies, which needs the deploy ability.', path: '/config/links/{uuid}/resume', operationId: 'resume-config-link', security: [['bearerAuth' => []]], tags: ['Project config'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'confirm', in: 'query', schema: new OA\Schema(type: 'boolean'))],
        responses: [new OA\Response(response: 200, description: 'The plan, or the link and its new revision.'), new OA\Response(response: 403, description: 'Team admins only.')]
    )]
    public function resume(Request $request, string $uuid, ConfigLinks $links): JsonResponse
    {
        $link = $this->adminLink($request, $uuid);
        if ($link instanceof JsonResponse) {
            return $link;
        }
        // A confirmed resume applies the file and deploys, like /apply.
        if ($request->boolean('confirm') && ! $request->user()->tokenCan('root') && ! $request->user()->tokenCan('deploy')) {
            return response()->json(['message' => 'Resuming with confirm=1 needs the deploy ability.'], 403);
        }
        $result = $links->resume($link, $request->user(), $request->boolean('confirm'));

        return response()->json(match (true) {
            $result instanceof ConfigPlan => $result->toArray(),
            $result instanceof EnvironmentConfigRevision => ['link' => self::serializeLink($link->fresh()), 'revision' => self::serializeRevision($result)],
            default => ['link' => self::serializeLink($link->fresh())],
        });
    }

    #[OA\Post(summary: 'Release a stuck apply', path: '/config/revisions/{uuid}/release', operationId: 'release-config-revision', security: [['bearerAuth' => []]], tags: ['Project config'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Released.'), new OA\Response(response: 403, description: 'Team admins only.')]
    )]
    public function release(Request $request, string $uuid, ConfigLinks $links): JsonResponse
    {
        $revision = $this->revision($uuid);
        if ($revision instanceof JsonResponse) {
            return $revision;
        }
        if (! $request->user()->isAdminOfTeam($revision->link->teamId())) {
            return response()->json(['message' => 'Only a team admin can release an apply.'], 403);
        }
        $links->release($revision, $request->user());

        return response()->json(self::serializeRevision($revision->fresh()));
    }

    #[OA\Post(summary: 'Retry a revision', path: '/config/revisions/{uuid}/retry', operationId: 'retry-config-revision', security: [['bearerAuth' => []]], tags: ['Project config'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 202, description: 'Planned again.'), new OA\Response(response: 403, description: 'Needs the deploy ability.')]
    )]
    public function retry(Request $request, string $uuid, ConfigLinks $links): JsonResponse
    {
        $revision = $this->revision($uuid);
        if ($revision instanceof JsonResponse) {
            return $revision;
        }
        if (! $request->user()->can('update', $revision->link->environment)) {
            return response()->json(['message' => 'You cannot change this environment.'], 403);
        }
        if ($revision->file_contents === null) {
            return response()->json(['message' => 'This revision has no file.'], 422);
        }

        return response()->json(self::serializeRevision($links->retry($revision, $request->user())), 202);
    }

    public static function serializeLink(EnvironmentConfigLink $link): array
    {
        return [
            'uuid' => $link->uuid,
            'mode' => $link->mode,
            'project' => $link->environment->project->name,
            'environment' => $link->environment->name,
            'environment_uuid' => $link->environment->uuid,
            'repository' => $link->repository,
            'branch' => $link->branch,
            'file_path' => $link->file_path,
            'server_uuid' => $link->readServer?->uuid,
            'destination_uuid' => $link->destination?->uuid,
            'auto_apply' => $link->auto_apply,
            'suspended_at' => $link->suspended_at,
            'last_applied_revision_uuid' => $link->lastAppliedRevision?->uuid,
            'created_at' => $link->created_at,
        ];
    }

    /**
     * The manual webhook of a git link. Shown once on create, and to team admins on the coolify.json page.
     *
     * @return array{webhook_secret: ?string, webhook_urls: array<string, string>}
     */
    public static function webhookDetails(EnvironmentConfigLink $link): array
    {
        $base = rtrim(base_url(), '/');

        return [
            'webhook_secret' => $link->webhook_secret,
            'webhook_urls' => collect(['github', 'gitlab', 'gitea', 'bitbucket'])
                ->mapWithKeys(fn (string $provider) => [$provider => "{$base}/webhooks/source/{$provider}/events/manual"])
                ->all(),
        ];
    }

    private static function canReadSensitive(): bool
    {
        return request()->attributes->get('can_read_sensitive', false) === true;
    }

    public static function serializeRevision(EnvironmentConfigRevision $revision): array
    {
        return [
            'uuid' => $revision->uuid,
            'trigger' => $revision->trigger,
            'commit_sha' => $revision->commit_sha,
            'file_hash' => $revision->file_hash,
            'status' => $revision->status->value,
            'error' => $revision->error,
            'plan' => self::canReadSensitive() ? $revision->plan : ConfigPlanView::withoutValues($revision->plan),
            'approved_at' => $revision->approved_at,
            'applied_at' => $revision->applied_at,
            'created_at' => $revision->created_at,
        ];
    }

    private function findLink(string $uuid): EnvironmentConfigLink|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $link = EnvironmentConfigLink::query()
            ->where('uuid', $uuid)
            ->whereHas('environment.project', fn ($query) => $query->where('team_id', $teamId))
            ->first();
        if ($link === null || ! auth()->user()->can('view', $link->environment)) {
            return response()->json(['message' => 'Link not found.'], 404);
        }

        return $link;
    }

    private function adminLink(Request $request, string $uuid): EnvironmentConfigLink|JsonResponse
    {
        $link = $this->findLink($uuid);
        if ($link instanceof EnvironmentConfigLink && ! $request->user()->isAdminOfTeam($link->teamId())) {
            return response()->json(['message' => 'Only a team admin can suspend or resume a link.'], 403);
        }

        return $link;
    }

    private function revision(string $uuid): EnvironmentConfigRevision|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $revision = EnvironmentConfigRevision::query()
            ->where('uuid', $uuid)
            ->whereHas('link.environment.project', fn ($query) => $query->where('team_id', $teamId))
            ->first();
        if ($revision === null || ! auth()->user()->can('view', $revision->link->environment)) {
            return response()->json(['message' => 'Revision not found.'], 404);
        }

        return $revision;
    }

    private function environment(string $uuid): Environment|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $environment = Environment::query()
            ->where('uuid', $uuid)
            ->whereHas('project', fn ($query) => $query->where('team_id', $teamId))
            ->first();
        if ($environment === null || ! auth()->user()->can('view', $environment)) {
            return response()->json(['message' => 'Environment not found.'], 404);
        }

        return $environment;
    }
}
