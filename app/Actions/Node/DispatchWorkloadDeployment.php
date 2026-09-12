<?php

namespace App\Actions\Node;

use App\Models\NodeOperation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class DispatchWorkloadDeployment
{
    use AsAction;

    /** @return array{command_id: string, observed_at_unix_ms: int, runtime_id: string, name: string, image: string} */
    public function handle(NodeOperation $operation): array
    {
        $operation->loadMissing(['node', 'workload.project', 'workload.environment', 'revision']);
        if ($operation->command_type !== 'workload.deploy.v1' || $operation->workload === null || $operation->revision === null) {
            throw new RuntimeException('The deployment operation is incomplete.');
        }

        $url = config('constants.flux.internal_url');
        $token = config('constants.flux.internal_token');
        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException('Flux internal API configuration is incomplete.');
        }

        $configuration = $this->validatedConfiguration($operation->revision->configuration ?? []);
        $name = 'coolify-'.$operation->workload->uuid.'-main';
        $response = Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(620)
            ->post(rtrim($url, '/').'/v1/commands/workload.deploy', [
                'server_id' => $operation->node->uuid,
                'command_id' => $operation->uuid,
                'name' => $name,
                'image' => $operation->revision->image,
                'command' => $configuration['command'] ?? [],
                'environment' => $configuration['environment'] ?? [],
                'ports' => $configuration['ports'] ?? [],
                'labels' => BuildContainerLabels::run($operation->workload, $operation->revision, 'main'),
                'restart_policy' => $configuration['restart_policy'] ?? 'unless-stopped',
            ]);
        $response->throw();

        $validator = Validator::make($response->json(), [
            'command_id' => ['required', 'string', 'in:'.$operation->uuid],
            'observed_at_unix_ms' => ['required', 'integer', 'min:1'],
            'runtime_id' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'in:'.$name],
            'image' => ['required', 'string', 'max:2048'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Flux returned an invalid deployment result.');
        }

        /** @var array{command_id: string, observed_at_unix_ms: int, runtime_id: string, name: string, image: string} */
        return $validator->validated();
    }

    /** @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    private function validatedConfiguration(array $configuration): array
    {
        $validator = Validator::make($configuration, [
            'command' => ['sometimes', 'array', 'max:64'],
            'command.*' => ['string', 'max:4096'],
            'environment' => ['sometimes', 'array', 'max:256'],
            'environment.*' => ['string', 'max:4096'],
            'ports' => ['sometimes', 'array', 'max:128'],
            'ports.*.host_ip' => ['nullable', 'ip'],
            'ports.*.host_port' => ['nullable', 'integer', 'between:1,65535'],
            'ports.*.container_port' => ['required', 'integer', 'between:1,65535'],
            'ports.*.protocol' => ['required', 'in:tcp,udp,sctp'],
            'restart_policy' => ['sometimes', 'in:no,always,on-failure,unless-stopped'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('The workload revision configuration is invalid.');
        }

        return $validator->validated();
    }
}
