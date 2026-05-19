<?php

namespace App\Livewire\Destination;

use App\Livewire\Destination\Concerns\ManagesKubernetesPods;
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
    use ManagesKubernetesPods;

    #[Locked]
    public $destination;

    public string $name = '';

    public string $network = '';

    public string $serverIp = '';

    public string $namespace = 'default';

    public bool $createNamespace = false;

    public string $context = '';

    public string $kubeconfigPath = '';

    public string $kubeconfig = '';

    public string $ingressClass = 'traefik';

    public string $ingressTlsSecret = '';

    public string $ingressAnnotations = '';

    public string $serviceType = 'ClusterIP';

    public string $serviceAccountName = '';

    public bool $createServiceAccount = false;

    public string $imagePullSecrets = '';

    public string $storageClass = '';

    public string $storageSize = '1Gi';

    public int $replicas = 1;

    public bool $autoscalingEnabled = false;

    public int $minReplicas = 1;

    public int $maxReplicas = 3;

    public int $targetCpuUtilizationPercentage = 70;

    public string $nodeSelector = '';

    public string $tolerations = '';

    public bool $podDisruptionBudgetEnabled = false;

    public string $podDisruptionBudgetMinAvailable = '';

    public function rules(): array
    {
        $rules = [
            'name' => ['string', 'required'],
            'serverIp' => ['string', 'required'],
        ];

        if ($this->destination instanceof KubernetesCluster) {
            return $rules + [
                'namespace' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'],
                'createNamespace' => ['required', 'boolean'],
                'context' => ['nullable', 'string', 'max:255'],
                'kubeconfigPath' => ['nullable', 'string', 'max:1024'],
                'kubeconfig' => ['nullable', 'string'],
                'ingressClass' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'],
                'ingressTlsSecret' => ['nullable', 'string', 'max:253', 'regex:/^[a-z0-9]([-a-z0-9.]*[a-z0-9])?$/'],
                'ingressAnnotations' => ['nullable', 'string', 'max:10000'],
                'serviceType' => ['required', 'string', 'in:ClusterIP,NodePort,LoadBalancer'],
                'serviceAccountName' => ['nullable', 'string', 'max:253', 'regex:/^[a-z0-9]([-a-z0-9.]*[a-z0-9])?$/'],
                'createServiceAccount' => ['required', 'boolean'],
                'imagePullSecrets' => ['nullable', 'string', 'max:5000'],
                'storageClass' => ['nullable', 'string', 'max:253'],
                'storageSize' => ['required', 'string', 'max:32', 'regex:/^[1-9][0-9]*(Ei|Pi|Ti|Gi|Mi|Ki|E|P|T|G|M|K)?$/'],
                'replicas' => ['required', 'integer', 'min:1', 'max:100'],
                'autoscalingEnabled' => ['required', 'boolean'],
                'minReplicas' => ['required', 'integer', 'min:1', 'max:100'],
                'maxReplicas' => ['required', 'integer', 'min:1', 'max:100'],
                'targetCpuUtilizationPercentage' => ['required', 'integer', 'min:1', 'max:100'],
                'nodeSelector' => ['nullable', 'string', 'max:10000'],
                'tolerations' => ['nullable', 'string', 'max:10000'],
                'podDisruptionBudgetEnabled' => ['required', 'boolean'],
                'podDisruptionBudgetMinAvailable' => ['nullable', 'string', 'max:16', 'regex:/^\d+%?$/'],
                'selectedKubernetesResource' => ['nullable', 'string', 'max:320', 'regex:/^$|^(Deployment|StatefulSet)\/[a-z0-9]([-a-z0-9.]*[a-z0-9])?$/'],
                'kubernetesResourceReplicas' => ['required', 'integer', 'min:0', 'max:100'],
                'selectedKubernetesPod' => ['nullable', 'string', 'max:253', 'regex:/^$|^[a-z0-9]([-a-z0-9.]*[a-z0-9])?$/'],
                'selectedKubernetesContainer' => ['nullable', 'string', 'max:253', 'regex:/^$|^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'],
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
                $this->destination->create_namespace = $this->createNamespace;
                $this->destination->context = blank($this->context) ? null : $this->context;
                $this->destination->kubeconfig_path = blank($this->kubeconfigPath) ? null : $this->kubeconfigPath;
                $this->destination->kubeconfig = blank($this->kubeconfig) ? null : $this->kubeconfig;
                $this->destination->ingress_class = $this->ingressClass;
                $this->destination->ingress_tls_secret = blank($this->ingressTlsSecret) ? null : $this->ingressTlsSecret;
                $this->destination->ingress_annotations = blank($this->ingressAnnotations) ? null : $this->ingressAnnotations;
                $this->destination->service_type = $this->serviceType;
                $this->destination->service_account_name = blank($this->serviceAccountName) ? null : $this->serviceAccountName;
                $this->destination->create_service_account = $this->createServiceAccount;
                $this->destination->image_pull_secrets = blank($this->imagePullSecrets) ? null : $this->imagePullSecrets;
                $this->destination->storage_class = blank($this->storageClass) ? null : $this->storageClass;
                $this->destination->storage_size = $this->storageSize;
                $this->destination->replicas = $this->replicas;
                $this->destination->autoscaling_enabled = $this->autoscalingEnabled;
                $this->destination->min_replicas = $this->minReplicas;
                $this->destination->max_replicas = $this->maxReplicas;
                $this->destination->target_cpu_utilization_percentage = $this->targetCpuUtilizationPercentage;
                $this->destination->node_selector = blank($this->nodeSelector) ? null : $this->nodeSelector;
                $this->destination->tolerations = blank($this->tolerations) ? null : $this->tolerations;
                $this->destination->pod_disruption_budget_enabled = $this->podDisruptionBudgetEnabled;
                $this->destination->pod_disruption_budget_min_available = blank($this->podDisruptionBudgetMinAvailable) ? null : $this->podDisruptionBudgetMinAvailable;
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
                $this->createNamespace = $this->destination->create_namespace;
                $this->context = $this->destination->context ?? '';
                $this->kubeconfigPath = $this->destination->kubeconfig_path ?? '';
                $this->kubeconfig = $this->destination->kubeconfig ?? '';
                $this->ingressClass = $this->destination->ingress_class;
                $this->ingressTlsSecret = $this->destination->ingress_tls_secret ?? '';
                $this->ingressAnnotations = $this->destination->ingress_annotations ?? '';
                $this->serviceType = $this->destination->service_type;
                $this->serviceAccountName = $this->destination->service_account_name ?? '';
                $this->createServiceAccount = $this->destination->create_service_account;
                $this->imagePullSecrets = $this->destination->image_pull_secrets ?? '';
                $this->storageClass = $this->destination->storage_class ?? '';
                $this->storageSize = $this->destination->storage_size ?? '1Gi';
                $this->replicas = $this->destination->replicas;
                $this->autoscalingEnabled = $this->destination->autoscaling_enabled;
                $this->minReplicas = $this->destination->min_replicas;
                $this->maxReplicas = $this->destination->max_replicas;
                $this->targetCpuUtilizationPercentage = $this->destination->target_cpu_utilization_percentage;
                $this->nodeSelector = $this->destination->node_selector ?? '';
                $this->tolerations = $this->destination->tolerations ?? '';
                $this->podDisruptionBudgetEnabled = $this->destination->pod_disruption_budget_enabled;
                $this->podDisruptionBudgetMinAvailable = $this->destination->pod_disruption_budget_min_available ?? '';
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
            $commandContext = $this->kubernetesCommandContext($builder);

            if ($commandContext === null) {
                return;
            }

            [$commands, $kubeconfigPath] = $commandContext;
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
