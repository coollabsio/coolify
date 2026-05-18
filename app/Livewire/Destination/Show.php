<?php

namespace App\Livewire\Destination;

use App\Models\KubernetesCluster;
use App\Models\StandaloneDocker;
use App\Services\Kubernetes\KubernetesKubectlCommandBuilder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public $destination;

    public string $name = '';

    public string $network = '';

    public string $serverIp = '';

    public string $namespace = 'default';

    public string $context = '';

    public string $kubeconfigPath = '';

    public string $kubeconfig = '';

    public string $ingressClass = 'traefik';

    public string $serviceType = 'ClusterIP';

    public int $replicas = 1;

    public bool $autoscalingEnabled = false;

    public int $minReplicas = 1;

    public int $maxReplicas = 3;

    public int $targetCpuUtilizationPercentage = 70;

    public function rules(): array
    {
        $rules = [
            'name' => ['string', 'required'],
            'serverIp' => ['string', 'required'],
        ];

        if ($this->destination instanceof KubernetesCluster) {
            return $rules + [
                'namespace' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'],
                'context' => ['nullable', 'string', 'max:255'],
                'kubeconfigPath' => ['nullable', 'string', 'max:1024'],
                'kubeconfig' => ['nullable', 'string'],
                'ingressClass' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'],
                'serviceType' => ['required', 'string', 'in:ClusterIP,NodePort,LoadBalancer'],
                'replicas' => ['required', 'integer', 'min:1', 'max:100'],
                'autoscalingEnabled' => ['required', 'boolean'],
                'minReplicas' => ['required', 'integer', 'min:1', 'max:100'],
                'maxReplicas' => ['required', 'integer', 'min:1', 'max:100'],
                'targetCpuUtilizationPercentage' => ['required', 'integer', 'min:1', 'max:100'],
            ];
        }

        return $rules + [
            'network' => ['string', 'required', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'],
        ];
    }

    public function mount(string $destination_uuid)
    {
        try {
            $destination = find_destination_for_current_team($destination_uuid);
            if (! $destination) {
                return redirect()->route('destination.index');
            }
            $this->destination = $destination;
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->destination->name = $this->name;

            if ($this->destination instanceof KubernetesCluster) {
                if ($this->autoscalingEnabled && $this->maxReplicas < $this->minReplicas) {
                    throw ValidationException::withMessages([
                        'maxReplicas' => 'Max replicas must be greater than or equal to min replicas.',
                    ]);
                }

                $this->destination->namespace = $this->namespace;
                $this->destination->context = blank($this->context) ? null : $this->context;
                $this->destination->kubeconfig_path = blank($this->kubeconfigPath) ? null : $this->kubeconfigPath;
                $this->destination->kubeconfig = blank($this->kubeconfig) ? null : $this->kubeconfig;
                $this->destination->ingress_class = $this->ingressClass;
                $this->destination->service_type = $this->serviceType;
                $this->destination->replicas = $this->replicas;
                $this->destination->autoscaling_enabled = $this->autoscalingEnabled;
                $this->destination->min_replicas = $this->minReplicas;
                $this->destination->max_replicas = $this->maxReplicas;
                $this->destination->target_cpu_utilization_percentage = $this->targetCpuUtilizationPercentage;
            } else {
                $this->destination->network = $this->network;
                $this->destination->server->ip = $this->serverIp;
            }

            $this->destination->save();
        } else {
            $this->name = $this->destination->name;
            $this->serverIp = $this->destination->server->ip;

            if ($this->destination instanceof KubernetesCluster) {
                $this->namespace = $this->destination->namespace;
                $this->context = $this->destination->context ?? '';
                $this->kubeconfigPath = $this->destination->kubeconfig_path ?? '';
                $this->kubeconfig = $this->destination->kubeconfig ?? '';
                $this->ingressClass = $this->destination->ingress_class;
                $this->serviceType = $this->destination->service_type;
                $this->replicas = $this->destination->replicas;
                $this->autoscalingEnabled = $this->destination->autoscaling_enabled;
                $this->minReplicas = $this->destination->min_replicas;
                $this->maxReplicas = $this->destination->max_replicas;
                $this->targetCpuUtilizationPercentage = $this->destination->target_cpu_utilization_percentage;
            } else {
                $this->network = $this->destination->network;
            }
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->destination);

            $this->syncData(true);
            $this->dispatch('success', 'Destination saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function testConnection()
    {
        try {
            $this->authorize('update', $this->destination);

            if (! ($this->destination instanceof KubernetesCluster)) {
                return;
            }

            $this->syncData(true);
            $this->destination->refresh();

            $builder = new KubernetesKubectlCommandBuilder;
            $commands = [
                'mkdir -p '.escapeshellarg($this->destination->configurationDirectory()),
            ];
            $kubeconfigPath = $this->destination->effectiveKubeconfigPath();

            if (filled($this->destination->kubeconfig)) {
                $commands[] = $builder->writeKubeconfig($this->destination->storedKubeconfigPath(), $this->destination->kubeconfig);
                $kubeconfigPath = $this->destination->storedKubeconfigPath();
            }

            if (blank($kubeconfigPath)) {
                $this->dispatch('error', 'Kubeconfig is required.');

                return;
            }

            $commands[] = $builder->version($this->destination, $kubeconfigPath);
            instant_remote_process($commands, $this->destination->server);
            $this->dispatch('success', 'Kubernetes connection verified.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete()
    {
        try {
            $this->authorize('delete', $this->destination);

            if ($this->destination instanceof KubernetesCluster && $this->destination->attachedTo()) {
                return $this->dispatch('error', 'You must delete all resources before deleting this destination.');
            }

            if ($this->destination->getMorphClass() === StandaloneDocker::class) {
                if ($this->destination->attachedTo()) {
                    return $this->dispatch('error', 'You must delete all resources before deleting this destination.');
                }
                $safeNetwork = escapeshellarg($this->destination->network);
                instant_remote_process(["docker network disconnect {$safeNetwork} coolify-proxy"], $this->destination->server, throwError: false);
                instant_remote_process(["docker network rm -f {$safeNetwork}"], $this->destination->server);
            }
            $this->destination->delete();

            return redirect()->route('destination.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.destination.show');
    }
}
