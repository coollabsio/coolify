<?php

namespace App\Actions\Sentinel;

use App\Models\Node;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class FetchFluxNodeInformation
{
    use AsAction;

    /**
     * @return array<string, mixed>
     */
    public function handle(Node $node): array
    {
        $node->ensureCapability('system.info.v1');
        $url = config('constants.flux.internal_url');
        $token = config('constants.flux.internal_token');
        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException('Flux internal API configuration is incomplete.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(12)
            ->post(rtrim($url, '/').'/v1/commands/system.info', [
                'server_id' => $node->uuid,
            ]);
        $response->throw();
        $payload = $response->json();
        $validator = Validator::make(is_array($payload) ? $payload : [], [
            'command_id' => ['required', 'string'],
            'observed_at_unix_ms' => ['required', 'integer'],
            'hostname' => ['nullable', 'string'],
            'operating_system' => ['nullable', 'string'],
            'operating_system_version' => ['nullable', 'string'],
            'kernel_version' => ['nullable', 'string'],
            'architecture' => ['nullable', 'string'],
            'cpu_count' => ['nullable', 'integer', 'min:1'],
            'memory_bytes' => ['nullable', 'integer', 'min:0'],
            'disk_total_bytes' => ['nullable', 'integer', 'min:0'],
            'disk_available_bytes' => ['nullable', 'integer', 'min:0'],
            'sentinel_version' => ['required', 'string'],
            'boot_id' => ['nullable', 'string'],
            'uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'container_runtime' => ['nullable', 'string'],
            'container_runtime_version' => ['nullable', 'string'],
            'cpu_usage_percent' => ['nullable', 'numeric', 'between:0,100'],
            'memory_used_bytes' => ['nullable', 'integer', 'min:0'],
            'memory_available_bytes' => ['nullable', 'integer', 'min:0'],
            'load_average_one' => ['nullable', 'numeric', 'min:0'],
            'load_average_five' => ['nullable', 'numeric', 'min:0'],
            'load_average_fifteen' => ['nullable', 'numeric', 'min:0'],
            'ingress_certificates' => ['nullable', 'array', 'max:10000'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Flux returned an invalid server information response.');
        }
        $information = $validator->validated();
        $operatingSystem = trim(implode(' ', array_filter([
            $information['operating_system'] ?? null,
            $information['operating_system_version'] ?? null,
        ])));
        $observedAt = Carbon::createFromTimestampMs($information['observed_at_unix_ms']);
        // Only the certificate issuer reports certificates. An empty list keeps the last report.
        $certificates = collect($information['ingress_certificates'] ?? [])
            ->filter(fn ($certificate): bool => is_array($certificate)
                && is_string($certificate['host'] ?? null)
                && is_string($certificate['status'] ?? null))
            ->map(fn (array $certificate): array => [
                'host' => mb_substr($certificate['host'], 0, 253),
                'status' => mb_substr($certificate['status'], 0, 32),
                'not_after' => (int) ($certificate['not_after'] ?? 0),
                'error' => mb_substr((string) ($certificate['error'] ?? ''), 0, 500),
                'next_attempt_at' => (int) ($certificate['next_attempt_at'] ?? 0),
            ])
            ->values()
            ->all();
        // Merged under a row lock: a network command may record its own keys while this request waits.
        $node->mergeMetadata([
            'hostname' => $information['hostname'] ?? null,
            'os' => $operatingSystem !== '' ? $operatingSystem : 'Unknown',
            'arch' => $information['architecture'] ?? 'Unknown',
            'kernel' => $information['kernel_version'] ?? 'Unknown',
            'cpus' => $information['cpu_count'] ?? 0,
            'memory_bytes' => $information['memory_bytes'] ?? 0,
            'disk_total_bytes' => $information['disk_total_bytes'] ?? null,
            'disk_available_bytes' => $information['disk_available_bytes'] ?? null,
            'uptime_since' => isset($information['uptime_seconds'])
                ? $observedAt->copy()->subSeconds($information['uptime_seconds'])->toIso8601String()
                : null,
            'sentinel_version' => $information['sentinel_version'],
            'boot_id' => $information['boot_id'] ?? null,
            'container_runtime' => $information['container_runtime'] ?? null,
            'container_runtime_version' => $information['container_runtime_version'] ?? null,
            'cpu_usage_percent' => $information['cpu_usage_percent'] ?? null,
            'memory_used_bytes' => $information['memory_used_bytes'] ?? null,
            'memory_available_bytes' => $information['memory_available_bytes'] ?? null,
            'load_average' => [
                'one' => $information['load_average_one'] ?? null,
                'five' => $information['load_average_five'] ?? null,
                'fifteen' => $information['load_average_fifteen'] ?? null,
            ],
            'collected_at' => $observedAt->toIso8601String(),
            'source' => 'flux',
            ...($certificates === [] ? [] : [
                'ingress_certificates' => $certificates,
                'ingress_certificates_at' => $observedAt->timestamp,
            ]),
        ], ['sentinel_version' => mb_substr($information['sentinel_version'], 0, 100)]);

        return $information;
    }
}
