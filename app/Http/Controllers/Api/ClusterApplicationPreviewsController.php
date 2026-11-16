<?php

namespace App\Http\Controllers\Api;

use App\Actions\Node\DeployNodePreview;
use App\Enums\NodeWorkloadDesiredState;
use App\Http\Controllers\Api\Concerns\HandlesClusterResourcesApi;
use App\Http\Controllers\Controller;
use App\Models\NodeWorkload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** The preview deployments of a cluster application from Git (decision 0025). */
class ClusterApplicationPreviewsController extends Controller
{
    use HandlesClusterResourcesApi;

    #[OA\Get(
        summary: 'List Previews',
        description: 'List the preview deployments of a cluster application, one for each pull request.',
        path: '/cluster-applications/{uuid}/previews',
        operationId: 'list-cluster-application-previews',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The previews.', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object', properties: [
                new OA\Property(property: 'uuid', type: 'string'),
                new OA\Property(property: 'pull_request_id', type: 'integer'),
                new OA\Property(property: 'pull_request_url', type: 'string', nullable: true),
                new OA\Property(property: 'pull_request_from_fork', type: 'boolean'),
                new OA\Property(property: 'status', type: 'string'),
                new OA\Property(property: 'removing', type: 'boolean'),
                new OA\Property(property: 'domains', type: 'array', items: new OA\Items(type: 'string')),
            ]))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $workload = $this->clusterWorkload($request, 'application');
        if ($workload === null) {
            return response()->json(['message' => 'Cluster application not found.'], 404);
        }
        $this->authorize('view', $workload);

        return response()->json($workload->previews()->with('nodes')->orderBy('pull_request_id')->get()->map(fn (NodeWorkload $preview): array => [
            'uuid' => $preview->uuid,
            'pull_request_id' => (int) $preview->pull_request_id,
            'pull_request_url' => $preview->pull_request_url,
            'pull_request_from_fork' => (bool) $preview->pull_request_from_fork,
            'status' => $this->workloadStatus($preview),
            'removing' => $preview->desired_state === NodeWorkloadDesiredState::REMOVED,
            'domains' => collect($preview->domain_routes ?? [])->map(fn (array $route): string => "{$route['scheme']}://{$route['host']}".($route['path'] ?? ''))->values()->all(),
        ])->values());
    }

    #[OA\Post(
        summary: 'Deploy Preview',
        description: 'Build and deploy the preview of a pull request, like Deploy on the Previews page. A manual deploy gets the values of a same-repository pull request, as in v4.',
        path: '/cluster-applications/{uuid}/previews',
        operationId: 'deploy-cluster-application-preview',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            type: 'object',
            required: ['pull_request_id'],
            properties: ['pull_request_id' => new OA\Property(property: 'pull_request_id', type: 'integer')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The preview build is queued.', content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'build_uuid', type: 'string'),
                new OA\Property(property: 'domain_error', type: 'string', nullable: true),
            ])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function deploy(Request $request): JsonResponse
    {
        $workload = $this->clusterWorkload($request, 'application');
        if ($workload === null) {
            return response()->json(['message' => 'Cluster application not found.'], 404);
        }
        $this->authorize('update', $workload);
        $invalid = $this->validateRequest($request, ['pull_request_id' => 'required|integer|min:1|max:2147483647']);
        if ($invalid !== null) {
            return $invalid;
        }
        $number = (int) $request->input('pull_request_id');

        return $this->runClusterAction(function () use ($request, $workload, $number): JsonResponse {
            ['build' => $build, 'domain_error' => $domainError] = DeployNodePreview::run($workload, $number, null, DeployNodePreview::manualRef($workload, $number), requestedBy: $request->user());

            return response()->json([
                'message' => $build->wasRecentlyCreated ? "Preview build of pull request #{$number} queued." : "A build of pull request #{$number} is already running.",
                'build_uuid' => $build->uuid,
                'domain_error' => $domainError,
            ]);
        });
    }
}
