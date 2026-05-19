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
            $this->dispatch('success', 'Kubernetes resources refreshed.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
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
