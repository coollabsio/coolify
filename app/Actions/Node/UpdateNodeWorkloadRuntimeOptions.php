<?php

namespace App\Actions\Node;

use App\Models\NodeWorkload;
use App\Models\User;
use App\Support\ContainerIsolation;
use App\Support\NodeRuntimeOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Saves the runtime options of a cluster application (decision 0032): Custom Docker Options,
 * container labels, the GPU, and the pre- and post-deployment commands. The next deploy uses them.
 */
class UpdateNodeWorkloadRuntimeOptions
{
    use AsAction;

    public const KEYS = [
        'custom_docker_run_options', 'custom_labels', 'is_gpu_enabled', 'gpu_count', 'gpu_device_ids',
        'pre_deployment_command', 'post_deployment_command', 'deployment_command_timeout',
    ];

    public const MAX_COMMAND_LENGTH = 16384;

    public const MAX_COMMAND_TIMEOUT = 3600;

    /**
     * @param  array<string, mixed>  $input  Changed settings only, with the keys of KEYS.
     *
     * @throws ValidationException With the key of each field.
     */
    public function handle(NodeWorkload $workload, array $input, User $user): NodeWorkload
    {
        Gate::forUser($user)->authorize('update', $workload);
        if ($workload->isDatabase() || $workload->node_stack_id !== null) {
            throw ValidationException::withMessages(['custom_docker_run_options' => 'A database or a stack service takes its container options from its own settings.']);
        }
        $input = collect($input)->only(self::KEYS)->all();
        foreach (['custom_docker_run_options', 'custom_labels', 'gpu_count', 'gpu_device_ids', 'pre_deployment_command', 'post_deployment_command'] as $key) {
            if (array_key_exists($key, $input)) {
                $input[$key] = filled($input[$key]) ? trim((string) $input[$key]) : null;
            }
        }
        Validator::make($input, [
            'custom_docker_run_options' => ['sometimes', 'nullable', 'string', 'max:16384'],
            'custom_labels' => ['sometimes', 'nullable', 'string', 'max:300000'],
            'is_gpu_enabled' => ['sometimes', 'boolean'],
            'gpu_count' => ['sometimes', 'nullable', 'string', 'max:8'],
            'gpu_device_ids' => ['sometimes', 'nullable', 'string', 'max:1400'],
            'pre_deployment_command' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_COMMAND_LENGTH],
            'post_deployment_command' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_COMMAND_LENGTH],
            'deployment_command_timeout' => ['sometimes', 'integer', 'between:1,'.self::MAX_COMMAND_TIMEOUT],
        ])->validate();

        $workload = DB::transaction(function () use ($workload, $input, $user): NodeWorkload {
            $locked = NodeWorkload::query()->lockForUpdate()->findOrFail($workload->id);
            $locked->fill($input);
            foreach ([
                'custom_docker_run_options' => fn () => NodeRuntimeOptions::parseDockerRunOptions($locked->custom_docker_run_options),
                'custom_labels' => fn () => NodeRuntimeOptions::parseLabels($locked->custom_labels),
                'gpu_count' => fn () => $locked->is_gpu_enabled ? NodeRuntimeOptions::gpuRequest($locked->gpu_count, $locked->gpu_device_ids) : null,
            ] as $key => $check) {
                try {
                    $check();
                } catch (RuntimeException $exception) {
                    throw ValidationException::withMessages([$key => $exception->getMessage()]);
                }
            }
            $settings = $locked->runtimeSettings();
            try {
                ContainerIsolation::ensureMayUseAdminTier($user, (int) $locked->team_id, $settings['runtime_options'] ?? []);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages(['custom_docker_run_options' => $exception->getMessage()]);
            }
            if (isset($settings['gpu'])) {
                foreach ($locked->nodes()->get() as $node) {
                    try {
                        DispatchWorkloadDeployment::cdiDevices($node, $settings['gpu']);
                    } catch (RuntimeException $exception) {
                        throw ValidationException::withMessages([$locked->is_gpu_enabled ? 'is_gpu_enabled' : 'custom_docker_run_options' => $exception->getMessage()]);
                    }
                }
            }
            $locked->save();

            return $locked;
        });

        return $workload->refresh();
    }
}
