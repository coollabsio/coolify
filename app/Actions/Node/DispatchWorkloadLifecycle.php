<?php

namespace App\Actions\Node;

use App\Enums\NodeContainerSlot;
use App\Enums\NodeWorkloadAction;
use App\Models\Node;
use App\Models\NodeOperation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class DispatchWorkloadLifecycle
{
    use AsAction;

    /** @return array{command_id: string, observed_at_unix_ms: int, name: string, action: string} */
    public function handle(NodeOperation $operation): array
    {
        $operation->loadMissing(['node', 'workload', 'revision']);
        $action = NodeWorkloadAction::tryFrom((string) data_get($operation->request, 'action'));
        if ($operation->command_type !== 'workload.lifecycle.v1' || $operation->workload === null || $operation->revision === null || $action === null) {
            throw new RuntimeException('The workload lifecycle operation is incomplete.');
        }

        $name = data_get($operation->request, 'container_name') ?? $operation->workload->containerNameOn($operation->node);
        $stopTimeout = data_get($operation->request, 'stop_timeout_seconds');
        $stopTimeout = is_int($stopTimeout) ? $stopTimeout : null;
        $result = self::send($operation->node, $operation->uuid, $name, $action, $stopTimeout);

        if ($action === NodeWorkloadAction::REMOVE && $operation->node->supportsCapability(DispatchWorkloadDeployment::ROLLING_CAPABILITY) === true) {
            // A rolling deploy that stopped halfway can leave a container in the other slot.
            foreach (NodeContainerSlot::cases() as $slot) {
                if ($slot->containerName($operation->workload) !== $name) {
                    self::send($operation->node, "{$operation->uuid}-{$slot->value}", $slot->containerName($operation->workload), $action, $stopTimeout);
                }
            }
        }

        return $result;
    }

    /** @return array{command_id: string, observed_at_unix_ms: int, name: string, action: string} */
    public static function send(Node $node, string $commandId, string $name, NodeWorkloadAction $action, ?int $stopTimeout = null): array
    {
        $url = config('constants.flux.internal_url');
        $token = config('constants.flux.internal_token');
        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException('Flux internal API configuration is incomplete.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(10)
            // Sentinel drains the endpoint and waits up to the stop timeout before the container stops.
            ->timeout(max(120, ($stopTimeout ?? 0) + 90))
            ->post(rtrim($url, '/').'/v1/commands/workload.lifecycle', [
                'server_id' => $node->uuid,
                'command_id' => $commandId,
                'name' => $name,
                'action' => $action->value,
                ...($stopTimeout === null ? [] : ['stop_timeout_seconds' => $stopTimeout]),
            ]);
        $response->throw();

        $validator = Validator::make($response->json(), [
            'command_id' => ['required', 'string', 'in:'.$commandId],
            'observed_at_unix_ms' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'in:'.$name],
            'action' => ['required', 'string', 'in:'.$action->value],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Flux returned an invalid workload lifecycle result.');
        }

        /** @var array{command_id: string, observed_at_unix_ms: int, name: string, action: string} */
        return $validator->validated();
    }
}
