<?php

namespace App\Actions\Node;

use App\Enums\NodeWorkloadAction;
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

        $url = config('constants.flux.internal_url');
        $token = config('constants.flux.internal_token');
        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException('Flux internal API configuration is incomplete.');
        }

        $name = 'coolify-'.$operation->workload->uuid.'-main';
        $response = Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(120)
            ->post(rtrim($url, '/').'/v1/commands/workload.lifecycle', [
                'server_id' => $operation->node->uuid,
                'command_id' => $operation->uuid,
                'name' => $name,
                'action' => $action->value,
            ]);
        $response->throw();

        $validator = Validator::make($response->json(), [
            'command_id' => ['required', 'string', 'in:'.$operation->uuid],
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
