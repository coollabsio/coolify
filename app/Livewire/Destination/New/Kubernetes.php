<?php

namespace App\Livewire\Destination\New;

use App\Models\KubernetesCluster;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Kubernetes extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public $servers;

    #[Locked]
    public Server $selectedServer;

    #[Validate(['required', 'string', 'max:255'])]
    public string $name;

    #[Validate(['required', 'string', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'])]
    public string $namespace = 'default';

    #[Validate(['nullable', 'string', 'max:255'])]
    public string $context = '';

    #[Validate(['nullable', 'string', 'max:1024'])]
    public string $kubeconfigPath = '';

    #[Validate(['nullable', 'string'])]
    public string $kubeconfig = '';

    #[Validate(['required', 'string', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'])]
    public string $ingressClass = 'traefik';

    #[Validate(['required', 'string', 'in:ClusterIP,NodePort,LoadBalancer'])]
    public string $serviceType = 'ClusterIP';

    #[Validate(['required', 'integer', 'min:1', 'max:100'])]
    public int $replicas = 1;

    #[Validate(['required', 'boolean'])]
    public bool $autoscalingEnabled = false;

    #[Validate(['required', 'integer', 'min:1', 'max:100'])]
    public int $minReplicas = 1;

    #[Validate(['required', 'integer', 'min:1', 'max:100'])]
    public int $maxReplicas = 3;

    #[Validate(['required', 'integer', 'min:1', 'max:100'])]
    public int $targetCpuUtilizationPercentage = 70;

    #[Validate(['required', 'string'])]
    public string $serverId;

    public function mount(?string $server_id = null): void
    {
        $this->servers = Server::isUsable()->get();
        $foundServer = $server_id ? $this->servers->find($server_id) : $this->servers->first();

        if (! $foundServer) {
            throw new \Exception('Server not found.');
        }

        $this->selectedServer = $foundServer;
        $this->serverId = $this->selectedServer->id;
        $this->generateName();
    }

    public function updatedServerId(): void
    {
        $this->selectedServer = $this->servers->find($this->serverId);
        $this->generateName();
    }

    public function generateName(): void
    {
        $name = data_get($this->selectedServer, 'name', new Cuid2);
        $this->name = str("{$name}-kubernetes")->kebab();
    }

    public function submit()
    {
        try {
            $this->authorize('create', KubernetesCluster::class);
            $this->validate();

            if (blank($this->kubeconfigPath) && blank($this->kubeconfig)) {
                throw ValidationException::withMessages([
                    'kubeconfigPath' => 'Provide a kubeconfig path or paste kubeconfig content.',
                ]);
            }
            if ($this->autoscalingEnabled && $this->maxReplicas < $this->minReplicas) {
                throw ValidationException::withMessages([
                    'maxReplicas' => 'Max replicas must be greater than or equal to min replicas.',
                ]);
            }

            $found = $this->selectedServer->kubernetesClusters()->where('name', $this->name)->first();

            if ($found) {
                throw ValidationException::withMessages([
                    'name' => 'A Kubernetes destination with this name already exists on this server.',
                ]);
            }

            $cluster = KubernetesCluster::create([
                'name' => $this->name,
                'namespace' => $this->namespace,
                'context' => blank($this->context) ? null : $this->context,
                'kubeconfig_path' => blank($this->kubeconfigPath) ? null : $this->kubeconfigPath,
                'kubeconfig' => blank($this->kubeconfig) ? null : $this->kubeconfig,
                'ingress_class' => $this->ingressClass,
                'service_type' => $this->serviceType,
                'replicas' => $this->replicas,
                'autoscaling_enabled' => $this->autoscalingEnabled,
                'min_replicas' => $this->minReplicas,
                'max_replicas' => $this->maxReplicas,
                'target_cpu_utilization_percentage' => $this->targetCpuUtilizationPercentage,
                'server_id' => $this->selectedServer->id,
            ]);

            redirectRoute($this, 'destination.show', [$cluster->uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
