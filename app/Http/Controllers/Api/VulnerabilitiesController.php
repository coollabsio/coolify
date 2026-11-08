<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Models\Service;
use App\Services\ImageScan\Finding;
use App\Services\ImageScan\ImageScanPolicyResolver;
use App\Services\ImageScan\RelevanceRules;
use App\Services\ImageScan\ResourceScans;
use App\Services\ImageScan\ResourceSecurity;
use App\Services\ImageScan\SecurityAction;
use App\Services\ImageScan\SecurityActions;
use App\Services\ImageScan\SecurityStatus;
use App\Services\ImageScan\TeamVulnerabilities;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use OpenApi\Attributes as OA;

/**
 * Image scan results, with the same data and permissions as the Security pages of the UI.
 */
#[OA\Parameter(parameter: 'VulnerabilityState', name: 'state', in: 'query', required: false, description: 'Comma-separated finding states: fixed, no_fix, ignored. All when not sent.', schema: new OA\Schema(type: 'string'))]
#[OA\Parameter(parameter: 'VulnerabilitySeverity', name: 'severity', in: 'query', required: false, description: 'Comma-separated severities: critical, high, medium, low, unknown.', schema: new OA\Schema(type: 'string'))]
#[OA\Parameter(parameter: 'VulnerabilityImage', name: 'image', in: 'query', required: false, description: 'Only the findings of this scanned image ref.', schema: new OA\Schema(type: 'string'))]
#[OA\Parameter(parameter: 'VulnerabilitySearch', name: 'search', in: 'query', required: false, description: 'Text in the vulnerability ID, an alias, the package, or the version.', schema: new OA\Schema(type: 'string'))]
#[OA\Parameter(parameter: 'VulnerabilityPage', name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1, minimum: 1))]
#[OA\Parameter(parameter: 'VulnerabilityPerPage', name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, maximum: 200, minimum: 1))]
class VulnerabilitiesController extends Controller
{
    private const SEVERITY_RANK = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1, 'unknown' => 0];

    private const FINDING_STATES = ['fixed', 'no_fix', 'ignored'];

    #[OA\Get(
        summary: 'List vulnerabilities',
        description: 'List the applications, services, and databases of the authenticated team with their stored security status from image scanning.',
        path: '/vulnerabilities',
        operationId: 'list-vulnerabilities',
        security: [['bearerAuth' => []]],
        tags: ['Vulnerabilities'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Comma-separated statuses to return.', schema: new OA\Schema(type: 'string', example: 'update_now,update_available')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resources with their security status, worst first.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'resources', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'uuid', type: 'string'),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'type', type: 'string', enum: ['application', 'service', 'database']),
                                new OA\Property(property: 'project_uuid', type: 'string', nullable: true),
                                new OA\Property(property: 'environment_uuid', type: 'string', nullable: true),
                                new OA\Property(property: 'server_uuid', type: 'string', nullable: true),
                                new OA\Property(property: 'images', properties: [
                                    new OA\Property(property: 'update', type: 'integer'),
                                    new OA\Property(property: 'total', type: 'integer'),
                                ], type: 'object'),
                                new OA\Property(property: 'security', ref: '#/components/schemas/SecurityStatus'),
                            ],
                            type: 'object',
                        )),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $statuses = $this->listParameter($request, 'status', TeamVulnerabilities::STATUS_ORDER);
        if ($statuses instanceof JsonResponse) {
            return $statuses;
        }

        $rank = array_flip(TeamVulnerabilities::STATUS_ORDER);
        $resources = TeamVulnerabilities::load((int) $teamId)->entries
            ->filter(fn (array $entry): bool => $statuses === [] || in_array($entry['security']->status->value, $statuses, true))
            ->sortBy(fn (array $entry): array => [$rank[$entry['security']->status->value] ?? PHP_INT_MAX, (string) $entry['resource']->getAttribute('name')])
            ->map(fn (array $entry): array => [
                'uuid' => (string) $entry['resource']->getAttribute('uuid'),
                'name' => (string) $entry['resource']->getAttribute('name'),
                'type' => $this->resourceType($entry['resource']),
                'project_uuid' => $entry['project']?->uuid,
                'environment_uuid' => $entry['environment']?->uuid,
                'server_uuid' => $entry['server']?->uuid,
                'images' => $entry['images'],
                'security' => $this->security($entry['security']),
            ])
            ->values();

        return response()->json([
            'resources' => $resources,
        ]);
    }

    #[OA\Get(
        summary: 'Get application vulnerabilities',
        description: 'Get the security status, the scanned images, and the vulnerabilities of an application.',
        path: '/applications/{uuid}/vulnerabilities',
        operationId: 'get-application-vulnerabilities',
        security: [['bearerAuth' => []]],
        tags: ['Vulnerabilities'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Application UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilityState'),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilitySeverity'),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilityImage'),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilitySearch'),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilityPage'),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilityPerPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Scan results.', content: new OA\JsonContent(ref: '#/components/schemas/ResourceVulnerabilities')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function application(Request $request): JsonResponse
    {
        return $this->show($request, 'application');
    }

    #[OA\Get(
        summary: 'Get service vulnerabilities',
        description: 'Get the security status, the scanned images, and the vulnerabilities of the applications and databases of a service.',
        path: '/services/{uuid}/vulnerabilities',
        operationId: 'get-service-vulnerabilities',
        security: [['bearerAuth' => []]],
        tags: ['Vulnerabilities'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Service UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilityState'),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilitySeverity'),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilityImage'),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilitySearch'),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilityPage'),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilityPerPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Scan results.', content: new OA\JsonContent(ref: '#/components/schemas/ResourceVulnerabilities')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function service(Request $request): JsonResponse
    {
        return $this->show($request, 'service');
    }

    #[OA\Get(
        summary: 'Get database vulnerabilities',
        description: 'Get the security status, the scanned image, and the vulnerabilities of a standalone database.',
        path: '/databases/{uuid}/vulnerabilities',
        operationId: 'get-database-vulnerabilities',
        security: [['bearerAuth' => []]],
        tags: ['Vulnerabilities'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Database UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilityState'),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilitySeverity'),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilityImage'),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilitySearch'),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilityPage'),
            new OA\Parameter(ref: '#/components/parameters/VulnerabilityPerPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Scan results.', content: new OA\JsonContent(ref: '#/components/schemas/ResourceVulnerabilities')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function database(Request $request): JsonResponse
    {
        return $this->show($request, 'database');
    }

    private function show(Request $request, string $type): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validator = customApiValidator($request->query(), [
            'state' => 'nullable|string',
            'severity' => 'nullable|string',
            'image' => 'nullable|string|max:1000',
            'search' => 'nullable|string|max:200',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }
        $states = $this->listParameter($request, 'state', self::FINDING_STATES);
        $severities = $this->listParameter($request, 'severity', array_keys(self::SEVERITY_RANK));
        foreach ([$states, $severities] as $parameter) {
            if ($parameter instanceof JsonResponse) {
                return $parameter;
            }
        }

        $resource = ResourceScans::find($type, (string) $request->route('uuid'), [(int) $teamId]);
        if (! $resource instanceof Model) {
            return response()->json(['message' => ucfirst($type).' not found.'], 404);
        }
        $this->authorize('view', $resource);

        $offState = ImageScanPolicyResolver::offState($resource, ImageScanPolicyResolver::for($resource)->scanServer);
        $scans = $offState === null ? ResourceScans::current($resource) : collect();
        $rules = [];
        $rulesFor = function (Model $owner) use (&$rules): RelevanceRules {
            return $rules[$owner->getMorphClass().':'.$owner->getKey()] ??= RelevanceRules::forResource($owner);
        };

        $imageStatuses = $scans->mapWithKeys(fn (ImageScan $scan): array => [(string) $scan->uuid => SecurityStatus::forScan($scan, $rulesFor($scan->resource))])->all();
        $findings = $this->findings($scans, $rulesFor);

        $image = trim((string) $request->query('image', ''));
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $findings = array_values(array_filter($findings, fn (array $row): bool => ($states === [] || in_array($row['state'], $states, true))
            && ($severities === [] || in_array($row['severity'], $severities, true))
            && ($image === '' || $row['image_ref'] === $image)
            && ($search === '' || str_contains(mb_strtolower(implode(' ', [$row['id'], ...$row['aliases'], $row['package'], $row['version']])), $search))));

        $perPage = (int) $request->query('per_page', 50);
        $page = (int) $request->query('page', 1);

        return response()->json([
            'uuid' => (string) $resource->getAttribute('uuid'),
            'type' => $type,
            'off_reason' => $offState,
            'security' => $scans->isEmpty() ? null : $this->security(SecurityStatus::forResource($resource, $scans, $imageStatuses)),
            'images' => $scans->map(fn (ImageScan $scan): array => [
                'scan_uuid' => (string) $scan->uuid,
                'owner_uuid' => (string) $scan->resource?->getAttribute('uuid'),
                'image_ref' => (string) $scan->image_ref,
                'scan_status' => $scan->status?->value,
                'error_code' => $scan->error_code?->value,
                'scanned_at' => $scan->scanned_at?->toIso8601String(),
                'truncated' => (bool) data_get($scan->summary, 'truncated', false),
                'security_status' => $imageStatuses[(string) $scan->uuid]->status->value,
            ])->values(),
            'findings' => array_slice($findings, ($page - 1) * $perPage, $perPage),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => count($findings)],
        ]);
    }

    /**
     * The findings of the current scans, worst first, in the same fix states as the UI.
     *
     * @param  Collection<int, ImageScan>  $scans
     * @param  Closure(Model): RelevanceRules  $rulesFor
     * @return list<array<string, mixed>>
     */
    private function findings(Collection $scans, Closure $rulesFor): array
    {
        $rows = [];
        foreach ($scans as $scan) {
            $owner = $scan->resource;
            $rules = $rulesFor($owner);
            $classify = SecurityActions::fixStates($scan, $rules);

            foreach (ResourceScans::findings($scan) as $finding) {
                $ignore = $rules->ignores->matches($finding);
                $rows[] = $this->finding($finding, $ignore === null ? $classify($finding)->value : 'ignored', $ignore) + [
                    'image_ref' => (string) $scan->image_ref,
                    'owner_uuid' => (string) $owner->getAttribute('uuid'),
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => [$b['kev'], self::SEVERITY_RANK[$b['severity']], $b['risk'] ?? 0.0]
            <=> [$a['kev'], self::SEVERITY_RANK[$a['severity']], $a['risk'] ?? 0.0]);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(Finding $finding, string $state, ?ImageScanIgnore $ignore): array
    {
        return [
            'id' => $finding->id,
            'display_id' => $finding->displayId,
            'aliases' => $finding->aliases,
            'title' => $finding->title,
            'severity' => TeamVulnerabilities::severityOf($finding),
            'package' => $finding->package,
            'version' => $finding->version,
            'package_type' => $finding->type,
            'fixed_in' => $finding->fixedIn,
            'state' => $state,
            'ignore' => $ignore === null ? null : [
                'reason' => $ignore->reason,
                'expires_at' => $ignore->expires_at?->toIso8601String(),
                'team_wide' => $ignore->resource_type === null,
            ],
            'kev' => $finding->kev,
            'epss' => $finding->epss,
            'risk' => $finding->risk,
            'cvss_score' => $finding->cvssScore,
            'advisory_url' => ResourceScans::advisoryUrl($finding->displayId),
        ];
    }

    /**
     * The security status without the internal facts and record IDs.
     *
     * @return array<string, mixed>
     */
    private function security(ResourceSecurity $security): array
    {
        return [
            'status' => $security->status->value,
            'label' => TeamVulnerabilities::statusLabel($security->status),
            'action' => TeamVulnerabilities::action($security)['text'] ?? null,
            'check_reason' => $security->checkReason,
            'problem_count' => $security->problemCount,
            'exploited_count' => $security->exploitedCount,
            'any_exploited' => $security->anyExploited,
            'critical_count' => $security->criticalCount,
            'high_count' => $security->highCount,
            'medium_count' => $security->mediumCount,
            'eol' => $security->eol,
            'checked_at' => $security->checkedAt?->toIso8601String(),
            'actions' => array_map(fn (SecurityAction $action): array => [
                'type' => $action->type->value,
                'sentence' => $action->sentence,
                'target' => $action->target,
                'copy' => $action->copy,
                'image' => $action->image,
                'ref' => $action->ref,
            ], $security->actions),
        ];
    }

    private function resourceType(Model $resource): string
    {
        return match (true) {
            $resource instanceof Application => 'application',
            $resource instanceof Service => 'service',
            default => 'database',
        };
    }

    /**
     * A comma-separated query parameter, checked against the allowed values. Empty when not sent.
     *
     * @param  list<string>  $allowed
     * @return list<string>|JsonResponse
     */
    private function listParameter(Request $request, string $name, array $allowed): array|JsonResponse
    {
        $raw = $request->query($name);
        if ($raw === null || $raw === '') {
            return [];
        }
        if (! is_string($raw)) {
            return response()->json(['message' => 'Validation failed.', 'errors' => [$name => ["The {$name} must be a comma-separated string."]]], 422);
        }

        $values = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($raw))))));
        $unknown = array_diff($values, $allowed);
        if ($unknown !== []) {
            return response()->json(['message' => 'Validation failed.', 'errors' => [$name => ['Allowed values: '.implode(', ', $allowed).'.']]], 422);
        }

        return $values;
    }
}
