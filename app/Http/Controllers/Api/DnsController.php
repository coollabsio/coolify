<?php

namespace App\Http\Controllers\Api;

use App\Enums\ManagedDnsDeletionResult;
use App\Exceptions\DnsRecordConflictException;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\DnsProviderZone;
use App\Models\IntegrationToken;
use App\Models\Service;
use App\Services\Dns\ResourceDnsRecords;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use RuntimeException;

#[OA\Schema(
    schema: 'ManagedDnsRecord',
    description: 'DNS record that Coolify manages at a DNS provider.',
    type: 'object',
    properties: [
        new OA\Property(property: 'uuid', type: 'string'),
        new OA\Property(property: 'hostname', type: 'string'),
        new OA\Property(property: 'type', type: 'string', enum: ['A', 'AAAA']),
        new OA\Property(property: 'address', type: 'string', description: 'Server IP address that the record points to.'),
        new OA\Property(property: 'owned', type: 'boolean', description: 'Coolify created the record, so it can delete it.'),
        new OA\Property(property: 'integration_token_uuid', type: 'string'),
    ],
)]
#[OA\Schema(
    schema: 'ResourceDnsStatus',
    description: 'DNS providers and managed records for the domains of a resource.',
    type: 'object',
    properties: [
        new OA\Property(property: 'default_address', type: 'string', nullable: true, description: 'Address of a new record when none is given: the first public server address. Null when the server has no public address.'),
        new OA\Property(property: 'public_addresses', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'hostnames', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'hostname', type: 'string'),
            new OA\Property(property: 'providers', type: 'array', items: new OA\Items(type: 'object', properties: [
                new OA\Property(property: 'integration_token_uuid', type: 'string'),
                new OA\Property(property: 'integration_token_name', type: 'string'),
                new OA\Property(property: 'zone', type: 'string'),
                new OA\Property(property: 'automatic_dns', type: 'boolean'),
                new OA\Property(property: 'proxied_dns', type: 'boolean'),
            ])),
            new OA\Property(property: 'records', type: 'array', items: new OA\Items(ref: '#/components/schemas/ManagedDnsRecord')),
        ])),
    ],
)]
#[OA\Schema(
    schema: 'CreateDnsRecordRequest',
    type: 'object',
    required: ['hostname'],
    properties: [
        new OA\Property(property: 'hostname', type: 'string', description: 'A saved domain of the resource.'),
        new OA\Property(property: 'integration_token_uuid', type: 'string', description: 'Cloudflare token to use. Required when several tokens manage the zone of the hostname.'),
        new OA\Property(property: 'address', type: 'string', description: 'A public address of the server. Default: default_address of the DNS status.'),
    ],
)]
#[OA\Schema(
    schema: 'ReplaceDnsRecordRequest',
    type: 'object',
    required: ['hostname', 'record_id', 'current'],
    properties: [
        new OA\Property(property: 'hostname', type: 'string', description: 'A saved domain of the resource.'),
        new OA\Property(property: 'integration_token_uuid', type: 'string', description: 'Cloudflare token to use. Required when several tokens manage the zone of the hostname.'),
        new OA\Property(property: 'record_id', type: 'string', description: 'conflict.record_id from the 409 response of the create request.'),
        new OA\Property(property: 'current', type: 'string', description: 'conflict.current from the 409 response. The record is replaced only when it still has this value.'),
        new OA\Property(property: 'address', type: 'string', description: 'A public address of the server. Default: default_address of the DNS status.'),
    ],
)]
#[OA\Schema(
    schema: 'DnsRecordConflict',
    type: 'object',
    properties: [
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'conflict', type: 'object', properties: [
            new OA\Property(property: 'record_id', type: 'string'),
            new OA\Property(property: 'current', type: 'string'),
            new OA\Property(property: 'proposed', type: 'string'),
        ]),
    ],
)]
class DnsController extends Controller
{
    public function __construct(private ResourceDnsRecords $dns) {}

    #[OA\Get(
        summary: 'List DNS Zones',
        description: 'List the DNS zones that the Cloudflare tokens of the team can access. Requires admin or owner.',
        path: '/dns/zones',
        operationId: 'list-dns-zones',
        security: [['bearerAuth' => []]],
        tags: ['DNS'],
        responses: [
            new OA\Response(response: 200, description: 'DNS zones.', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object', properties: [
                new OA\Property(property: 'zone', type: 'string'),
                new OA\Property(property: 'account_name', type: 'string', nullable: true),
                new OA\Property(property: 'integration_token_uuid', type: 'string'),
                new OA\Property(property: 'integration_token_name', type: 'string'),
                new OA\Property(property: 'managed_records_count', type: 'integer'),
            ]))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
        ],
    )]
    public function zones(): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $this->authorize('viewAny', IntegrationToken::class);

        $zones = DnsProviderZone::query()
            ->whereHas('integrationToken', fn ($query) => $query->where('team_id', $teamId)->where('provider', 'cloudflare'))
            ->with('integrationToken')->withCount('managedRecords')->orderBy('name')->get();

        return response()->json($zones->map(fn (DnsProviderZone $zone): array => [
            'zone' => $zone->name,
            'account_name' => $zone->account_name,
            'integration_token_uuid' => $zone->integrationToken->uuid,
            'integration_token_name' => $zone->integrationToken->name,
            'managed_records_count' => $zone->managed_records_count,
        ])->values());
    }

    #[OA\Get(
        summary: 'Get Application DNS',
        description: 'DNS providers and managed DNS records for the domains of an application.',
        path: '/applications/{uuid}/dns',
        operationId: 'get-application-dns',
        security: [['bearerAuth' => []]],
        tags: ['DNS'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Application UUID.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'DNS status.', content: new OA\JsonContent(ref: '#/components/schemas/ResourceDnsStatus')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'No access to the application.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function applicationStatus(Request $request): JsonResponse
    {
        return $this->status($this->findResource($request, Application::class, 'view'));
    }

    #[OA\Get(
        summary: 'Get Service DNS',
        description: 'DNS providers and managed DNS records for the domains of a service.',
        path: '/services/{uuid}/dns',
        operationId: 'get-service-dns',
        security: [['bearerAuth' => []]],
        tags: ['DNS'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Service UUID.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'DNS status.', content: new OA\JsonContent(ref: '#/components/schemas/ResourceDnsStatus')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'No access to the service.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function serviceStatus(Request $request): JsonResponse
    {
        return $this->status($this->findResource($request, Service::class, 'view'));
    }

    #[OA\Post(
        summary: 'Create Application DNS Record',
        description: 'Create a DNS record at Cloudflare that points a domain of the application to its server. An existing record with the same address is adopted.',
        path: '/applications/{uuid}/dns',
        operationId: 'create-application-dns-record',
        security: [['bearerAuth' => []]],
        tags: ['DNS'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Application UUID.', schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreateDnsRecordRequest')),
        responses: [
            new OA\Response(response: 201, description: 'DNS record created.', content: new OA\JsonContent(properties: [new OA\Property(property: 'record', ref: '#/components/schemas/ManagedDnsRecord')])),
            new OA\Response(response: 400, description: 'Cloudflare rejected the change.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'No write access to the application.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'Another record uses the hostname. Replace it with the replace endpoint.', content: new OA\JsonContent(ref: '#/components/schemas/DnsRecordConflict')),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
            new OA\Response(response: 423, description: 'Another DNS change for the hostname is in progress. Try again.'),
        ],
    )]
    public function createApplicationRecord(Request $request): JsonResponse
    {
        return $this->create($request, $this->findResource($request, Application::class, 'update'));
    }

    #[OA\Post(
        summary: 'Create Service DNS Record',
        description: 'Create a DNS record at Cloudflare that points a domain of the service to its server. An existing record with the same address is adopted.',
        path: '/services/{uuid}/dns',
        operationId: 'create-service-dns-record',
        security: [['bearerAuth' => []]],
        tags: ['DNS'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Service UUID.', schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreateDnsRecordRequest')),
        responses: [
            new OA\Response(response: 201, description: 'DNS record created.', content: new OA\JsonContent(properties: [new OA\Property(property: 'record', ref: '#/components/schemas/ManagedDnsRecord')])),
            new OA\Response(response: 400, description: 'Cloudflare rejected the change.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'No write access to the service.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'Another record uses the hostname. Replace it with the replace endpoint.', content: new OA\JsonContent(ref: '#/components/schemas/DnsRecordConflict')),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
            new OA\Response(response: 423, description: 'Another DNS change for the hostname is in progress. Try again.'),
        ],
    )]
    public function createServiceRecord(Request $request): JsonResponse
    {
        return $this->create($request, $this->findResource($request, Service::class, 'update'));
    }

    #[OA\Post(
        summary: 'Replace Application DNS Record',
        description: 'Point the conflicting record from a 409 create response to the server of the application.',
        path: '/applications/{uuid}/dns/replace',
        operationId: 'replace-application-dns-record',
        security: [['bearerAuth' => []]],
        tags: ['DNS'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Application UUID.', schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReplaceDnsRecordRequest')),
        responses: [
            new OA\Response(response: 200, description: 'DNS record replaced.', content: new OA\JsonContent(properties: [new OA\Property(property: 'record', ref: '#/components/schemas/ManagedDnsRecord')])),
            new OA\Response(response: 400, description: 'Cloudflare rejected the change, or the record changed since the conflict.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'No write access to the application.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
            new OA\Response(response: 423, description: 'Another DNS change for the hostname is in progress. Try again.'),
        ],
    )]
    public function replaceApplicationRecord(Request $request): JsonResponse
    {
        return $this->replace($request, $this->findResource($request, Application::class, 'update'));
    }

    #[OA\Post(
        summary: 'Replace Service DNS Record',
        description: 'Point the conflicting record from a 409 create response to the server of the service.',
        path: '/services/{uuid}/dns/replace',
        operationId: 'replace-service-dns-record',
        security: [['bearerAuth' => []]],
        tags: ['DNS'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Service UUID.', schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReplaceDnsRecordRequest')),
        responses: [
            new OA\Response(response: 200, description: 'DNS record replaced.', content: new OA\JsonContent(properties: [new OA\Property(property: 'record', ref: '#/components/schemas/ManagedDnsRecord')])),
            new OA\Response(response: 400, description: 'Cloudflare rejected the change, or the record changed since the conflict.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'No write access to the service.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
            new OA\Response(response: 423, description: 'Another DNS change for the hostname is in progress. Try again.'),
        ],
    )]
    public function replaceServiceRecord(Request $request): JsonResponse
    {
        return $this->replace($request, $this->findResource($request, Service::class, 'update'));
    }

    #[OA\Delete(
        summary: 'Release Application DNS Record',
        description: 'Release the managed DNS record of a hostname that the application no longer uses. Coolify deletes the record at Cloudflare only when it created the record, the record did not change, and no other resource uses the hostname. Removing a domain through the API already queues this release; use this endpoint to retry it or to keep the record.',
        path: '/applications/{uuid}/dns/{hostname}',
        operationId: 'release-application-dns-record',
        security: [['bearerAuth' => []]],
        tags: ['DNS'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Application UUID.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'hostname', in: 'path', required: true, description: 'Hostname of the record.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'delete_record', in: 'query', required: false, description: 'Delete the record at Cloudflare. Default: true.', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Record released.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'result', type: 'string', enum: ['deleted', 'already_gone', 'not_owned', 'changed_externally', 'kept']),
                new OA\Property(property: 'message', type: 'string'),
            ])),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'No write access to the application.'),
            new OA\Response(response: 404, description: 'No managed record of the application for the hostname.'),
            new OA\Response(response: 422, description: 'The application still uses the hostname.'),
            new OA\Response(response: 423, description: 'Another DNS change for the hostname is in progress. Try again.'),
            new OA\Response(response: 502, description: 'Cloudflare did not respond. Try again.'),
        ],
    )]
    public function releaseApplicationRecord(Request $request): JsonResponse
    {
        return $this->release($request, $this->findResource($request, Application::class, 'update'));
    }

    #[OA\Delete(
        summary: 'Release Service DNS Record',
        description: 'Release the managed DNS record of a hostname that the service no longer uses. Coolify deletes the record at Cloudflare only when it created the record, the record did not change, and no other resource uses the hostname. Removing a domain through the API already queues this release; use this endpoint to retry it or to keep the record.',
        path: '/services/{uuid}/dns/{hostname}',
        operationId: 'release-service-dns-record',
        security: [['bearerAuth' => []]],
        tags: ['DNS'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Service UUID.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'hostname', in: 'path', required: true, description: 'Hostname of the record.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'delete_record', in: 'query', required: false, description: 'Delete the record at Cloudflare. Default: true.', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Record released.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'result', type: 'string', enum: ['deleted', 'already_gone', 'not_owned', 'changed_externally', 'kept']),
                new OA\Property(property: 'message', type: 'string'),
            ])),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'No write access to the service.'),
            new OA\Response(response: 404, description: 'No managed record of the service for the hostname.'),
            new OA\Response(response: 422, description: 'The service still uses the hostname.'),
            new OA\Response(response: 423, description: 'Another DNS change for the hostname is in progress. Try again.'),
            new OA\Response(response: 502, description: 'Cloudflare did not respond. Try again.'),
        ],
    )]
    public function releaseServiceRecord(Request $request): JsonResponse
    {
        return $this->release($request, $this->findResource($request, Service::class, 'update'));
    }

    /**
     * The resource of the token team, authorized for the ability. Errors are returned as a response.
     *
     * @param  class-string<Application|Service>  $class
     */
    private function findResource(Request $request, string $class, string $ability): Application|Service|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $query = $class === Application::class
            ? Application::ownedByCurrentTeamAPI($teamId)
            : Service::whereRelation('environment.project.team', 'id', $teamId);
        $resource = $query->where('uuid', $request->route('uuid'))->first();
        if ($resource === null) {
            return response()->json(['message' => class_basename($class).' not found.'], 404);
        }

        $this->authorize($ability, $resource);

        return $resource;
    }

    private function status(Application|Service|JsonResponse $resource): JsonResponse
    {
        if ($resource instanceof JsonResponse) {
            return $resource;
        }

        return response()->json($this->dns->status($resource));
    }

    private function create(Request $request, Application|Service|JsonResponse $resource): JsonResponse
    {
        if ($resource instanceof JsonResponse) {
            return $resource;
        }

        $body = $this->validateBody($request, [
            'hostname' => ['required', 'string', 'max:255'],
            'integration_token_uuid' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
        ]);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->runDnsChange($body['hostname'], function () use ($resource, $body): JsonResponse {
            $record = $this->dns->create($resource, $body['hostname'], $body['integration_token_uuid'] ?? null, $body['address'] ?? null);

            return response()->json(['record' => $this->dns->recordData($record->load('integrationToken'))], 201);
        });
    }

    private function replace(Request $request, Application|Service|JsonResponse $resource): JsonResponse
    {
        if ($resource instanceof JsonResponse) {
            return $resource;
        }

        $body = $this->validateBody($request, [
            'hostname' => ['required', 'string', 'max:255'],
            'integration_token_uuid' => ['nullable', 'string'],
            'record_id' => ['required', 'string'],
            'current' => ['required', 'string'],
            'address' => ['nullable', 'string'],
        ]);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->runDnsChange($body['hostname'], function () use ($resource, $body): JsonResponse {
            $record = $this->dns->replace(
                $resource,
                $body['hostname'],
                $body['integration_token_uuid'] ?? null,
                $body['record_id'],
                $body['current'],
                $body['address'] ?? null,
            );

            return response()->json(['record' => $this->dns->recordData($record->load('integrationToken'))]);
        });
    }

    private function release(Request $request, Application|Service|JsonResponse $resource): JsonResponse
    {
        if ($resource instanceof JsonResponse) {
            return $resource;
        }

        $hostname = (string) $request->route('hostname');
        try {
            $result = $this->dns->release($resource, $hostname, $request->boolean('delete_record', true));
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => "No managed DNS record of this resource for {$hostname}."], 404);
        }

        return match ($result) {
            ManagedDnsDeletionResult::Busy => response()->json(['message' => $this->busyMessage($hostname)], 423),
            ManagedDnsDeletionResult::Failed => response()->json(['message' => 'Cloudflare did not respond. The record was left untouched; try again.'], 502),
            default => response()->json([
                'result' => $result?->value ?? 'kept',
                'message' => match ($result) {
                    ManagedDnsDeletionResult::Deleted => 'The DNS record was deleted.',
                    ManagedDnsDeletionResult::AlreadyGone => 'The DNS record no longer existed.',
                    ManagedDnsDeletionResult::NotOwned => 'Coolify did not create the DNS record, so it was kept.',
                    ManagedDnsDeletionResult::ChangedExternally => 'The DNS record changed outside Coolify, so it was kept.',
                    default => 'The DNS record was kept: another resource uses the hostname, or delete_record was false.',
                },
            ]),
        };
    }

    /**
     * Runs a create or replace and turns DNS errors into responses.
     */
    private function runDnsChange(string $hostname, callable $change): JsonResponse
    {
        try {
            return $change();
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (DnsRecordConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'conflict' => ['record_id' => $e->providerRecordId, 'current' => $e->currentValue, 'proposed' => $e->proposedValue],
            ], 409);
        } catch (LockTimeoutException) {
            return response()->json(['message' => $this->busyMessage(strtolower($hostname))], 423);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * @param  array<string, array<int, string>>  $rules
     * @return array<string, mixed>|JsonResponse
     */
    private function validateBody(Request $request, array $rules): array|JsonResponse
    {
        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $body = $request->json()->all();
        $validator = customApiValidator($body, $rules);
        $extraFields = array_diff(array_keys($body), array_keys($rules));
        if ($validator->fails() || $extraFields !== []) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        return $validator->validated();
    }

    private function busyMessage(string $hostname): string
    {
        return "Another DNS change for {$hostname} is in progress. Try again in a few seconds.";
    }
}
