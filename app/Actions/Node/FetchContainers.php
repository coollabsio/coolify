<?php

namespace App\Actions\Node;

use App\Models\Node;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class FetchContainers
{
    use AsAction;

    public function handle(Node $node): int
    {
        $url = config('constants.flux.internal_url');
        $token = config('constants.flux.internal_token');
        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException('Flux internal API configuration is incomplete.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(20)
            ->post(rtrim($url, '/').'/v1/commands/container.list', [
                'server_id' => $node->uuid,
            ]);
        $response->throw();

        $validator = Validator::make($response->json(), [
            'command_id' => ['required', 'string'],
            'observed_at_unix_ms' => ['required', 'integer', 'min:1'],
            'containers' => ['present', 'array', 'max:10000'],
            'containers.*.runtime_id' => ['required', 'string', 'max:128', 'distinct'],
            'containers.*.name' => ['required', 'string', 'max:255'],
            'containers.*.image' => ['required', 'string', 'max:2048'],
            'containers.*.state' => ['required', 'string', 'max:100'],
            'containers.*.health_status' => ['nullable', 'string', 'max:100'],
            'containers.*.restart_count' => ['nullable', 'integer', 'min:0'],
            'containers.*.ports' => ['present', 'array'],
            'containers.*.ports.*.host_ip' => ['nullable', 'string', 'max:255'],
            'containers.*.ports.*.host_port' => ['nullable', 'integer', 'between:1,65535'],
            'containers.*.ports.*.container_port' => ['required', 'integer', 'between:1,65535'],
            'containers.*.ports.*.protocol' => ['required', 'in:tcp,udp,sctp'],
            'containers.*.labels' => ['present', 'array'],
            'containers.*.labels.*' => ['nullable', 'string', 'max:4096'],
            'containers.*.created_at_unix_ms' => ['nullable', 'integer', 'min:1'],
            'containers.*.started_at_unix_ms' => ['nullable', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Flux returned invalid container inventory.');
        }

        $data = $validator->validated();
        $observedAt = Carbon::createFromTimestampMs($data['observed_at_unix_ms'])->toIso8601String();
        $containers = collect($data['containers'])->map(fn (array $container): array => [
            'runtime_id' => $container['runtime_id'],
            'name' => $container['name'],
            'image' => $container['image'],
            'state' => $container['state'],
            'health_status' => $container['health_status'] ?? null,
            'restart_count' => $container['restart_count'] ?? null,
            'ports' => $container['ports'],
            'labels' => $container['labels'],
            'created_at' => isset($container['created_at_unix_ms'])
                ? Carbon::createFromTimestampMs($container['created_at_unix_ms'])->toIso8601String()
                : null,
            'started_at' => isset($container['started_at_unix_ms'])
                ? Carbon::createFromTimestampMs($container['started_at_unix_ms'])->toIso8601String()
                : null,
            'observed_at' => $observedAt,
        ])->all();

        ReconcileContainers::run($node, $containers);

        return count($containers);
    }
}
