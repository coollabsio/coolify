<?php

namespace App\Livewire\Destination\Concerns;

use App\Models\KubernetesCluster;
use App\Services\Kubernetes\KubernetesKubectlCommandBuilder;
use App\Services\Kubernetes\KubernetesPodStatusParser;
use App\Services\Kubernetes\KubernetesResourceStatusParser;

trait ManagesKubernetesPods
{
    public array $kubernetesPods = [];

    public array $kubernetesResources = [];

    public string $selectedKubernetesResource = '';

    public int $kubernetesResourceReplicas = 1;

    public string $selectedKubernetesPod = '';

    public string $selectedKubernetesContainer = '';

    public string $kubernetesPodLogs = '';

    public function refreshKubernetesPods()
    {
        try {
            $this->authorize('view', $this->destination);

            if (! ($this->destination instanceof KubernetesCluster)) {
                return;
            }

            $builder = new KubernetesKubectlCommandBuilder;
            $commandContext = $this->kubernetesCommandContext($builder);

            if ($commandContext === null) {
                return;
            }

            [$commands, $kubeconfigPath] = $commandContext;
            $output = instant_remote_process([
                ...$commands,
                $builder->getPods($this->destination, kubeconfigPath: $kubeconfigPath),
            ], $this->destination->server);

            $this->kubernetesPods = (new KubernetesPodStatusParser)->parse($output ?: '{"items":[]}');

            if (! collect($this->kubernetesPods)->contains('name', $this->selectedKubernetesPod)) {
                $this->selectedKubernetesPod = data_get($this->kubernetesPods, '0.name', '');
            }

            $this->updatedSelectedKubernetesPod();
            $this->dispatch('success', 'Kubernetes Pods refreshed.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function refreshKubernetesResources()
    {
        try {
            $this->authorize('view', $this->destination);

            if (! ($this->destination instanceof KubernetesCluster)) {
                return;
            }

            $builder = new KubernetesKubectlCommandBuilder;
            $commandContext = $this->kubernetesCommandContext($builder);

            if ($commandContext === null) {
                return;
            }

            [$commands, $kubeconfigPath] = $commandContext;
            $output = instant_remote_process([
                ...$commands,
                $builder->getResources($this->destination, kubeconfigPath: $kubeconfigPath),
            ], $this->destination->server);

            $this->kubernetesResources = (new KubernetesResourceStatusParser)->parse($output ?: '{"items":[]}');
            $this->syncSelectedKubernetesResource();
            $this->dispatch('success', 'Kubernetes resources refreshed.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updatedSelectedKubernetesResource(): void
    {
        $this->syncSelectedKubernetesResource();
    }

    public function selectedKubernetesResourcePayload(): ?array
    {
        if (blank($this->selectedKubernetesResource)) {
            return null;
        }

        [$kind, $name] = array_pad(explode('/', $this->selectedKubernetesResource, 2), 2, null);

        return collect($this->kubernetesResources)
            ->first(fn (array $resource) => $resource['kind'] === $kind && $resource['name'] === $name && $resource['scalable'] === true);
    }

    public function scaleSelectedKubernetesResource(): void
    {
        try {
            $resource = $this->selectedKubernetesResourcePayload();
            if (! ($this->destination instanceof KubernetesCluster) || $resource === null) {
                $this->dispatch('error', 'Select a scalable Kubernetes resource first.');

                return;
            }

            $this->authorize('update', $this->destination);
            $this->validateOnly('selectedKubernetesResource');
            $this->validateOnly('kubernetesResourceReplicas');

            $builder = new KubernetesKubectlCommandBuilder;
            $commandContext = $this->kubernetesCommandContext($builder);

            if ($commandContext === null) {
                return;
            }

            [$commands, $kubeconfigPath] = $commandContext;
            $commands[] = $resource['kind'] === 'StatefulSet'
                ? $builder->scaleStatefulSet($this->destination, $resource['name'], $this->kubernetesResourceReplicas, $kubeconfigPath)
                : $builder->scaleDeployment($this->destination, $resource['name'], $this->kubernetesResourceReplicas, $kubeconfigPath);
            instant_remote_process($commands, $this->destination->server);

            $this->dispatch('success', 'Kubernetes resource scale requested.');
            $this->refreshKubernetesResources();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function restartSelectedKubernetesResource(): void
    {
        try {
            $resource = $this->selectedKubernetesResourcePayload();
            if (! ($this->destination instanceof KubernetesCluster) || $resource === null) {
                $this->dispatch('error', 'Select a scalable Kubernetes resource first.');

                return;
            }

            $this->authorize('update', $this->destination);
            $this->validateOnly('selectedKubernetesResource');

            $builder = new KubernetesKubectlCommandBuilder;
            $commandContext = $this->kubernetesCommandContext($builder);

            if ($commandContext === null) {
                return;
            }

            [$commands, $kubeconfigPath] = $commandContext;
            $commands[] = $resource['kind'] === 'StatefulSet'
                ? $builder->rolloutRestartStatefulSet($this->destination, $resource['name'], $kubeconfigPath)
                : $builder->rolloutRestart($this->destination, $resource['name'], $kubeconfigPath);
            instant_remote_process($commands, $this->destination->server);

            $this->dispatch('success', 'Kubernetes resource restart requested.');
            $this->refreshKubernetesResources();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function updatedSelectedKubernetesPod(): void
    {
        $this->kubernetesPodLogs = '';
        $containers = $this->selectedKubernetesPodContainers();
        if (! in_array($this->selectedKubernetesContainer, $containers, true)) {
            $this->selectedKubernetesContainer = $containers[0] ?? '';
        }
    }

    private function syncSelectedKubernetesResource(): void
    {
        $resource = $this->selectedKubernetesResourcePayload();

        if ($resource === null) {
            $resource = collect($this->kubernetesResources)->firstWhere('scalable', true);
            $this->selectedKubernetesResource = $resource ? "{$resource['kind']}/{$resource['name']}" : '';
        }

        $this->kubernetesResourceReplicas = max(0, (int) ($resource['desired_replicas'] ?? $this->kubernetesResourceReplicas));
    }

    public function selectedKubernetesPodContainers(): array
    {
        $pod = collect($this->kubernetesPods)->firstWhere('name', $this->selectedKubernetesPod);

        return $pod['container_names'] ?? [];
    }

    public function loadSelectedKubernetesPodLogs()
    {
        try {
            if (! ($this->destination instanceof KubernetesCluster) || blank($this->selectedKubernetesPod)) {
                $this->dispatch('error', 'Select a Kubernetes Pod first.');

                return;
            }

            $this->authorize('view', $this->destination);
            $this->validateOnly('selectedKubernetesPod');
            $this->validateOnly('selectedKubernetesContainer');

            $builder = new KubernetesKubectlCommandBuilder;
            $commandContext = $this->kubernetesCommandContext($builder);

            if ($commandContext === null) {
                return;
            }

            [$commands, $kubeconfigPath] = $commandContext;
            $this->kubernetesPodLogs = instant_remote_process([
                ...$commands,
                $builder->podLogs(
                    $this->destination,
                    $this->selectedKubernetesPod,
                    blank($this->selectedKubernetesContainer) ? null : $this->selectedKubernetesContainer,
                    kubeconfigPath: $kubeconfigPath
                ),
            ], $this->destination->server) ?? '';
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function restartSelectedKubernetesPod()
    {
        try {
            if (! ($this->destination instanceof KubernetesCluster) || blank($this->selectedKubernetesPod)) {
                $this->dispatch('error', 'Select a Kubernetes Pod first.');

                return;
            }

            $this->authorize('update', $this->destination);
            $this->validateOnly('selectedKubernetesPod');

            $builder = new KubernetesKubectlCommandBuilder;
            $commandContext = $this->kubernetesCommandContext($builder);

            if ($commandContext === null) {
                return;
            }

            [$commands, $kubeconfigPath] = $commandContext;
            instant_remote_process([
                ...$commands,
                $builder->deletePod($this->destination, $this->selectedKubernetesPod, $kubeconfigPath),
            ], $this->destination->server);

            $this->kubernetesPodLogs = '';
            $this->dispatch('success', 'Kubernetes Pod restart requested.');
            $this->refreshKubernetesPods();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function kubernetesCommandContext(KubernetesKubectlCommandBuilder $builder): ?array
    {
        if (! ($this->destination instanceof KubernetesCluster)) {
            return null;
        }

        $commands = ['mkdir -p '.escapeshellarg($this->destination->configurationDirectory())];
        $kubeconfigPath = $this->destination->effectiveKubeconfigPath();

        if (filled($this->destination->kubeconfig)) {
            $commands[] = $builder->writeKubeconfig($this->destination->storedKubeconfigPath(), $this->destination->kubeconfig);
            $kubeconfigPath = $this->destination->storedKubeconfigPath();
        }

        if (blank($kubeconfigPath)) {
            $this->dispatch('error', 'Kubeconfig is required.');

            return null;
        }

        return [$commands, $kubeconfigPath];
    }
}
