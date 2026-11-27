<?php

namespace App\Livewire\Project\ClusterApplication;

use App\Actions\Node\UpdateNodeWorkloadRuntimeOptions;
use App\Models\NodeCluster;
use App\Models\NodeWorkload;
use App\Support\NodeRuntimeOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * The v4 container settings of a cluster application on its General page (decision 0032):
 * Custom Docker Options, container labels, the GPU, and the pre- and post-deployment commands.
 * UpdateNodeWorkloadRuntimeOptions validates and saves them. The next deploy applies them.
 */
class RuntimeOptions extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public NodeWorkload $workload;

    public string $customDockerRunOptions = '';

    public string $customLabels = '';

    public bool $gpuEnabled = false;

    public string $gpuCount = '';

    public string $gpuDeviceIds = '';

    public string $preDeploymentCommand = '';

    public string $postDeploymentCommand = '';

    public int $deploymentCommandTimeout = 300;

    /** The fields of the action and their properties. */
    private const FIELDS = [
        'custom_docker_run_options' => 'customDockerRunOptions',
        'custom_labels' => 'customLabels',
        'is_gpu_enabled' => 'gpuEnabled',
        'gpu_count' => 'gpuCount',
        'gpu_device_ids' => 'gpuDeviceIds',
        'pre_deployment_command' => 'preDeploymentCommand',
        'post_deployment_command' => 'postDeploymentCommand',
        'deployment_command_timeout' => 'deploymentCommandTimeout',
    ];

    public function mount(NodeWorkload $workload): void
    {
        $this->authorize('view', $workload);
        $this->workload = $workload;
        $this->loadOptions();
    }

    public function save(): void
    {
        $this->authorize('update', $this->workload);
        $this->resetErrorBag();
        $input = [];
        foreach (self::FIELDS as $key => $property) {
            $input[$key] = $this->{$property};
        }
        try {
            $this->workload = UpdateNodeWorkloadRuntimeOptions::run($this->workload, $input, auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError(self::FIELDS[$key] ?? 'customDockerRunOptions', $messages[0]);
            }

            return;
        } catch (Throwable $exception) {
            handleError($exception, $this);

            return;
        }
        $this->loadOptions();
        $this->dispatch('success', 'Container settings saved. Redeploy to apply the Docker options, labels, and GPU.');
    }

    private function loadOptions(): void
    {
        $workload = $this->workload;
        $this->customDockerRunOptions = (string) $workload->custom_docker_run_options;
        $this->customLabels = (string) $workload->custom_labels;
        $this->gpuEnabled = (bool) $workload->is_gpu_enabled;
        $this->gpuCount = (string) $workload->gpu_count;
        $this->gpuDeviceIds = (string) $workload->gpu_device_ids;
        $this->preDeploymentCommand = (string) $workload->pre_deployment_command;
        $this->postDeploymentCommand = (string) $workload->post_deployment_command;
        $this->deploymentCommandTimeout = (int) ($workload->deployment_command_timeout ?: 300);
    }

    public function render(): View
    {
        $clusters = NodeCluster::query()->whereKey($this->workload->clusterIds())->with('nodes')->get();
        $nodes = $clusters->flatMap->nodes;
        try {
            $proxyLabels = NodeRuntimeOptions::parseLabels($this->workload->custom_labels)['proxy_labels'];
        } catch (Throwable) {
            $proxyLabels = false;
        }

        return view('livewire.project.cluster-application.runtime-options', [
            'supported' => $nodes->isNotEmpty() && $nodes->every(fn ($node): bool => $node->supportsCapability('workload.runtime_options.v2') === true),
            'proxyLabels' => $proxyLabels,
        ]);
    }
}
